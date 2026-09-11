# syntax=docker/dockerfile:1

# --- Imagen: PHP vanilla (sin composer, sin build step) + Apache ---
FROM php:8.2-apache

# Dependencias del sistema para compilar pdo_pgsql, y curl para healthcheck
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        curl \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

# mod_rewrite: requerido por public/.htaccess (el front controller enruta
# todo a index.php vía switch sobre REQUEST_URI)
RUN a2enmod rewrite

# DocumentRoot -> public/ (el código vive en /var/www/html, la app sirve desde /public)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e "s!/var/www/html!\${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf \
    && sed -ri -e "s!/var/www/!\${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# AllowOverride All para que .htaccess tenga efecto en public/
RUN { \
        echo '<Directory ${APACHE_DOCUMENT_ROOT}>'; \
        echo '    AllowOverride All'; \
        echo '    Require all granted'; \
        echo '</Directory>'; \
    } > /etc/apache2/conf-available/neo-qr.conf \
    && a2enconf neo-qr

WORKDIR /var/www/html

# Copiar el código de la app (ver .dockerignore para lo excluido)
COPY . /var/www/html

# Sello de build: fecha real de construcción de la imagen. No se puede derivar
# del mtime de un archivo porque EasyPanel hace `git fetch` sobre una copia
# existente y git solo reescribe los archivos que cambiaron en el commit.
RUN date -u +'%d/%m/%Y %H:%M UTC' > /var/www/html/.build

EXPOSE 80

# Usa el endpoint /health de la propia app
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fs http://localhost/health || exit 1

# La imagen base ya define el CMD de apache2-foreground
