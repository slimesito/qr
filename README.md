# qr

Generación y administración de **códigos QR seriados por cliente**, pensados
para grabarse con láser sobre placas metálicas y pegarse en el mundo físico.

El panel emite tandas de códigos únicos (`QRABCDEF`), lleva el inventario de
qué código pertenece a quién, y exporta el lote en los formatos que espera una
grabadora. Cada QR codifica una URL, lleva el logo del cliente en el centro y
se dibuja como SVG vectorial a la medida exacta de la placa.

**PHP 8.2 vanilla + PostgreSQL. Sin framework, sin Composer, sin `vendor/`, sin
build step.** Unas 5.500 líneas propias, incluido el encoder de QR.

---

## Lo técnicamente interesante

Este proyecto tiene tres o cuatro problemas que no se resuelven instalando una
dependencia. Lo que sigue es el resumen; el razonamiento completo de cada
decisión —incluido lo que se descartó y por qué— está en
[CLAUDE.md](CLAUDE.md), que funciona como documento de diseño.

### Encoder de QR escrito desde cero

[`app/Support/QrMatrix.php`](app/Support/QrMatrix.php) — 736 líneas, sin
librerías de terceros: aritmética sobre campo de Galois GF(256), polinomio
generador de Reed-Solomon, entrelazado de bloques, las 8 máscaras evaluadas
por penalización según la norma, patrones de búsqueda y alineación, e
información de formato y versión. Cubre modo alfanumérico y byte, nivel de
corrección H y versiones 1 a 10.

No es reinventar la rueda por deporte: **el tamaño del módulo es una
restricción de producto**. La placa mide lo que mide, así que menos módulos =
módulos más grandes = el código se lee desde más lejos. Detectar solo el modo
alfanumérico y emitir la URL en mayúsculas —el esquema y el host son
insensibles a mayúsculas por RFC 3986— baja el código de versión 6 (41×41) a
versión 4 (33×33): el módulo pasa de ~0,51 mm a ~0,61 mm sobre una placa de
25 mm.

### El hueco del logo se dimensiona con Reed-Solomon, no con una regla de tres

Un logo encima del QR no produce borrones sino **errores**: el lector no sabe
que esos módulos están tapados, los lee mal y se come un valor equivocado.
Reed-Solomon corrige la mitad de errores que de borrones, y el entrelazado
reparte un cuadrado central de forma despareja entre bloques.

Así que el tamaño del hueco por versión sale de **recorrer el zigzag real del
encoder y contar cuántos codewords rompe el bloque más golpeado**, no de un
porcentaje de área. Se usa como máximo el 75% de ese presupuesto: el 25%
restante queda para la suciedad, los rayones y la mala luz sobre una placa que
vive a la intemperie.

| versión | lado | hueco | peor bloque | logo (% del área) |
|---|---|---|---|---|
| 3 | 29×29 | 9 | 8/11 · 73% | 6,5% |
| 4 | 33×33 | 11 | 6/8 · 75% | 8,1% |
| 5 | 37×37 | 11 | 6/11 · 55% | 6,5% |
| 6 | 41×41 | 13 | 7/14 · 50% | 7,7% |
| 7 | 45×45 | 17 | 9/13 · 69% | 11,7% |

### Unión booleana de módulos y eliminación de nodos colineales

[`QrSvg::path()`](app/Support/QrSvg.php) no dibuja un `<rect>` por módulo ni
fusiona rectángulos: emite de cada celda oscura **sólo los lados cuyo vecino
está apagado** —los lados compartidos saldrían en sentidos opuestos— y encadena
esas aristas en contornos cerrados. Eso *es* la unión booleana, sin ninguna
librería de geometría. Sobre cada contorno se eliminan después los nodos
colineales, de forma topológicamente exacta: nada se aproxima, se redondea ni
se mueve.

Un patrón de búsqueda entero pasa de 4 rectángulos (16 nodos) a **3 contornos
de 4 nodos**. Medido sobre 42 códigos reales: **~15% menos nodos y ~22% menos
bytes** de path. Y no es sólo tamaño de archivo — desaparecen las costuras que
la grabadora marca en el material al rellenar figura por figura, y el cabezal
deja de frenar en nodos redundantes.

La cobertura está verificada reparseando el path y evaluando el *winding* en el
centro de cada módulo, **contra las dos reglas de relleno** (`nonzero` y
`evenodd`), porque del otro lado hay importadores de láser de los que no se
sabe cuál aplican.

### Salida pensada para parsers tontos

Los importadores de LightBurn o RDWorks no son navegadores. Por eso el SVG de
grabado va con milímetros reales en `width`/`height` (sin unidad, cada programa
supone su propio DPI), sin el `<rect>` blanco de fondo (lo importaría como una
figura más y grabaría el cuadrado entero), con `stroke="none"` explícito, y
colocando cada pieza con un `<g transform="translate() scale()">` en vez de un
`<svg viewBox>` anidado — pedirle a un parser simple que resuelva un viewBox
fuera de la raíz del documento es apostar a que lo hace bien.

### Saneador de SVG por lista blanca

Los clientes suben su logo como SVG y el markup termina incrustado
`innerHTML` en el panel y con `echo` crudo en la hoja imprimible.
[`SaneadorSvg`](app/Support/SaneadorSvg.php) **reconstruye el documento desde
cero** permitiendo sólo un puñado de elementos geométricos con atributos
validados por charset, en vez de borrar por lista negra sobre el DOM cargado:
así "lo que no está permitido" nunca tiene un hueco por el que colarse.
Rechaza con mensajes explícitos lo que no puede grabarse (texto sin convertir a
curvas, degradados, imágenes incrustadas) en vez de perder parte del dibujo en
silencio.

### Otros detalles

- **`.zip` armado a mano** ([`Support/Zip.php`](app/Support/Zip.php)): la
  imagen de producción no trae `ext-zip`, y sumar `libzip` para empaquetar unos
  SVG de texto no se justifica.
- **Serial opaco** (`QR` + 6 letras sorteadas con `random_int`): no deja deducir
  el cliente, la fecha ni el número de la tanda, y por lo tanto no deja adivinar
  los códigos vecinos. La unicidad la garantiza el `UNIQUE` de Postgres con
  `ON CONFLICT DO NOTHING`, no un `SELECT` previo — chequear y después insertar
  deja una ventana de carrera.
- **Link público por lote** para pasarle a la grabadora sin darle cuenta:
  token de 7 símbolos sobre un alfabeto sin caracteres confundibles (`0 1 I L O U`
  afuera), porque se dicta por teléfono y se retipea desde un papel. Es sorteado
  y no firmado a propósito: un HMAC llevaría el lote en claro al lado de la
  firma, y un carácter mal tipeado tiene que dar 404, nunca caer en otro lote
  existente.
- **Autenticación sin tabla de usuarios**: hash bcrypt en base64 en el entorno
  (un `$` sin escapar lo trunca en varios sistemas de variables, en silencio),
  `SameSite=Strict` en lugar de token CSRF, y un "recordarme" de 30 días por
  cookie firmada cuya **clave HMAC es el propio hash de la contraseña** — así
  cambiar la contraseña invalida todos los dispositivos recordados sin
  necesidad de otra variable ni de una tabla de tokens.

---

## Stack

| | |
|---|---|
| Lenguaje | PHP 8.2, MVC, autoload PSR-4 propio (`spl_autoload_register`) |
| Base | PostgreSQL vía PDO, esquema versionado en [`db/schema.sql`](db/schema.sql) |
| Front | Vista PHP + API JSON *action-based* + JS vanilla con `fetch` |
| Imagen QR | SVG generado en PHP, sin GD ni Imagick |
| Infra | `php:8.2-apache` + `mod_rewrite`, docroot en `public/` |

Cero dependencias de terceros en runtime. No hay `composer.json`.

## Cómo correrlo

Requiere Docker y un PostgreSQL accesible. El `docker-compose.yml` levanta
**sólo la app** y espera una base ya existente en el host (ver
["Desarrollo local" en CLAUDE.md](CLAUDE.md) para el detalle y el porqué).

```bash
# 1. Crear la base y aplicar el esquema (idempotente)
psql -c "CREATE DATABASE neoqr;"
psql -v ON_ERROR_STOP=1 -d neoqr < db/schema.sql

# 2. Configurar el entorno
cp .env.example .env
#    Editar DB_*, y generar el hash de la contraseña del panel:
php -r 'echo base64_encode(password_hash("la-que-elijas", PASSWORD_DEFAULT)), "\n";'

# 3. Levantar
docker compose up -d --build   # http://localhost:8080
```

Sin `ADMIN_PASSWORD_HASH_B64` el login queda **bloqueado**, no abierto. Sin
`QR_BASE_URL` no se emite ninguna imagen: la URL queda embebida en el código
impreso y un lote mal generado no se arregla sin reimprimir.

## Estructura

```
app/
├── Controllers/   Auth, Cliente, Serial, Qr, Health
├── Models/        Database (PDO singleton), Cliente, Serial, Enlace
├── Support/       QrMatrix, QrSvg, QrLogo, SaneadorSvg, Zip,
│                  Auth, FormatoSerial, FormatoEnlace, Respuesta
└── Views/         Layouts, Auth, Clientes, Seriales
config/            app.php, database.php (env obligatorias)
db/schema.sql      esquema versionado e idempotente
public/            front controller + .htaccess + js/
```

Router por `switch` sobre el path en [`public/index.php`](public/index.php).
Todo lo dinámico va por query string. Las respuestas de la API usan el envelope
`{ok, data|error}` **con el código HTTP real** (400/401/404/409/500), no un 200
con `ok:false` adentro.

| Ruta | Qué hace |
|---|---|
| `/clientes` | Panel: clientes, lotes, generación |
| `/api/clientes`, `/api/seriales` | API JSON |
| `/qr` | Hoja imprimible de control |
| `/qr.zip` | Un SVG por código, para grabar placa por placa |
| `/qr-hoja.svg` | Un solo SVG con todo el lote ubicado en hojas A4 |
| `/qr.svg`, `/qr-unidad.svg` | Un código suelto (pantalla / grabado) |
| `/<TOKEN>` + `/grabado*` | Link público por lote, sin sesión |
| `/health` | Healthcheck, verifica la conexión a la base |

## Alcance

El sistema **genera e inventaría** códigos. Qué responde la URL que lleva
impresa cada placa es una decisión de producto que quedó explícitamente fuera
de alcance, y hoy devuelve 404. Está documentado como tal, no es un pendiente
olvidado: cuando se defina, la forma que menos ata es guardar el destino por
serial en la base y redirigir, para que el código impreso nunca quede obsoleto.

## Documentación de diseño

[CLAUDE.md](CLAUDE.md) es el documento largo: qué se decidió, qué se descartó y
por qué. Incluye las cuentas de módulos y presupuesto de corrección, el
contrato del logo, cómo se arma la hoja A4, el modelo de datos con el
razonamiento de cada columna, y los modos de falla que costaron un rato
encontrar.
