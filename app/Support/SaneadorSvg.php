<?php

namespace App\Support;

/**
 * Saneo del SVG que sube cada cliente como logo.
 *
 * Va aparte de QrLogo a propósito: esta clase es ~200 líneas de recorrido de
 * DOM, listas permitidas y mensajes en castellano que cambian cada vez que
 * aparece la rareza de un exportador nuevo (Figma, Illustrator, Inkscape...);
 * QrLogo es el objeto de valor que cambia cuando cambia el contrato de
 * render. Son razones de cambio distintas.
 *
 * El criterio es **reconstruir, no mutar**: se camina el árbol del SVG
 * subido y se emite un string nuevo con sólo lo permitido, en vez de borrar
 * nodos del DOM cargado y reserializarlo. Así "sacar en silencio" es
 * simplemente no emitir el nodo, y no queda ningún hueco de lista negra por
 * donde se cuele markup no contemplado — es además la defensa contra XSS,
 * porque el resultado se incrusta inline en el panel autenticado y en la
 * hoja imprimible.
 */
class SaneadorSvg
{
    private const TOPE_CRUDO = 262144; // 256 KB: cualquier SVG razonable entra bien adentro.
    private const TOPE_SANEADO = 6144; // 6 KB: ver la nota de tamaño más abajo.
    private const TOPE_NODOS = 2000;
    private const TOPE_PROFUNDIDAD = 32;

    // Contenedores: se emiten y se recorren los hijos. "a" y "switch" se
    // desenvuelven (no aportan geometría propia).
    private const CONTENEDORES = ['g', 'a', 'switch'];

    // Figuras: hojas del árbol, no tienen hijos con geometría propia.
    private const FIGURAS = ['path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon'];

    // Se rechaza el archivo entero con un mensaje propio: sacar el elemento
    // en silencio haría desaparecer parte del dibujo sin aviso.
    private const RECHAZADOS = [
        'text' => 'El SVG tiene texto sin convertir a curvas. Convertí el texto a trazos antes de exportar.',
        'tspan' => 'El SVG tiene texto sin convertir a curvas. Convertí el texto a trazos antes de exportar.',
        'textpath' => 'El SVG tiene texto sin convertir a curvas. Convertí el texto a trazos antes de exportar.',
        'image' => 'El SVG tiene una imagen incrustada. El logo tiene que ser vectorial (sólo figuras, sin imágenes).',
        'use' => 'El SVG usa referencias internas (<use>). Exportalo con las figuras expandidas, sin referencias.',
        'symbol' => 'El SVG usa referencias internas (<symbol>). Exportalo con las figuras expandidas.',
        'lineargradient' => 'El logo tiene degradados. Tiene que ser de un solo color: el QR se graba en un solo material.',
        'radialgradient' => 'El logo tiene degradados. Tiene que ser de un solo color: el QR se graba en un solo material.',
        'pattern' => 'El logo tiene un patrón de relleno. Tiene que ser de un solo color.',
        'mask' => 'El SVG usa máscaras. El logo tiene que ser geometría simple de un solo color.',
        'foreignobject' => 'El SVG tiene contenido embebido no vectorial (foreignObject).',
        'marker' => 'El SVG usa marcadores (<marker>). Exportalo sin marcadores.',
    ];

    // Se saltean en silencio (sacarlos no cambia el dibujo, o el caso real
    // dominante es un no-op): clipPath/defs — Figma envuelve casi todo export
    // en un <g clip-path="url(#clip0)"> con un rect del tamaño del frame, así
    // que rechazarlo rechazaría la mayoría de los logos de Figma — más
    // style/class/filter/title/desc/metadata/comentarios.
    private const SALTEADOS = ['clippath', 'defs', 'style', 'title', 'desc', 'metadata', 'filter'];

    // Atributos que sobreviven, con su geometría propia. Todo lo demás se
    // descarta (id, class, style ya rescatado aparte, on*, href, data-*,
    // opacidades: no significan nada para una grabadora).
    private const ATRIBUTOS_PERMITIDOS = [
        'd', 'x', 'y', 'width', 'height', 'rx', 'ry', 'cx', 'cy', 'r',
        'x1', 'y1', 'x2', 'y2', 'points', 'transform',
        'fill-rule', 'clip-rule',
        'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
    ];

    private const NAMESPACE_SVG = 'http://www.w3.org/2000/svg';

    private int $nodos = 0;

    public static function sanear(string $crudo): QrLogo
    {
        return (new self())->procesar($crudo);
    }

    private function procesar(string $crudo): QrLogo
    {
        $crudo = trim($crudo);

        if ($crudo === '') {
            throw new \InvalidArgumentException('No se subió ningún archivo');
        }

        if (strlen($crudo) > self::TOPE_CRUDO) {
            throw new \InvalidArgumentException('El archivo es demasiado grande (máximo 256 KB)');
        }

        $erroresPrevios = libxml_use_internal_errors(true);

        try {
            $doc = new \DOMDocument();
            $cargado = $doc->loadXML($crudo, LIBXML_NONET);

            if (!$cargado) {
                throw new \InvalidArgumentException('No se pudo leer el archivo: no parece un SVG válido');
            }

            if ($doc->doctype !== null) {
                throw new \InvalidArgumentException('El SVG trae un DOCTYPE, que no se acepta por seguridad');
            }

            $raiz = $doc->documentElement;

            if ($raiz === null || strtolower($raiz->localName ?? '') !== 'svg') {
                throw new \InvalidArgumentException('El archivo no es un SVG: falta el elemento <svg> raíz');
            }

            [$minX, $minY, $ancho, $alto] = $this->extraerViewBox($raiz);

            $markup = $this->recorrer($raiz, 0);

            if (trim($markup) === '') {
                throw new \InvalidArgumentException('No quedó ninguna figura utilizable: el SVG no tiene geometría');
            }

            if (strlen($markup) > self::TOPE_SANEADO) {
                throw new \InvalidArgumentException(
                    'El logo es demasiado complejo. Usá una versión simplificada del isotipo '
                    . '(entra en un cuadrado de pocos milímetros, no hace falta detalle fino).'
                );
            }

            return new QrLogo($minX, $minY, $ancho, $alto, $markup);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($erroresPrevios);
        }
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}
     */
    private function extraerViewBox(\DOMElement $raiz): array
    {
        $viewBox = trim($raiz->getAttribute('viewBox'));

        if ($viewBox !== '') {
            $partes = preg_split('/[\s,]+/', $viewBox) ?: [];

            if (count($partes) === 4 && array_reduce($partes, static fn($ok, $p) => $ok && is_numeric($p), true)) {
                [$minX, $minY, $ancho, $alto] = array_map('floatval', $partes);

                if ($ancho > 0 && $alto > 0) {
                    return [$minX, $minY, $ancho, $alto];
                }
            }
        }

        $ancho = $this->numeroSinUnidad($raiz->getAttribute('width'));
        $alto = $this->numeroSinUnidad($raiz->getAttribute('height'));

        if ($ancho !== null && $alto !== null && $ancho > 0 && $alto > 0) {
            return [0.0, 0.0, $ancho, $alto];
        }

        throw new \InvalidArgumentException('El SVG no declara viewBox ni un tamaño (width/height) utilizable');
    }

    private function numeroSinUnidad(string $valor): ?float
    {
        $valor = trim($valor);

        if ($valor === '' || !preg_match('/^(-?[0-9]*\.?[0-9]+)\s*(px|pt)?$/', $valor, $m)) {
            return null;
        }

        return (float) $m[1];
    }

    private function recorrer(\DOMElement $nodo, int $profundidad): string
    {
        if ($profundidad > self::TOPE_PROFUNDIDAD) {
            return '';
        }

        $salida = '';

        foreach ($nodo->childNodes as $hijo) {
            if (!($hijo instanceof \DOMElement)) {
                continue; // comentarios, texto suelto, instrucciones de proceso: se ignoran.
            }

            $namespace = $hijo->namespaceURI;
            if ($namespace !== null && $namespace !== self::NAMESPACE_SVG) {
                continue; // sodipodi:, inkscape:, rdf:, dc:, etc.
            }

            $nombre = strtolower($hijo->localName ?? '');

            if (isset(self::RECHAZADOS[$nombre])) {
                throw new \InvalidArgumentException(self::RECHAZADOS[$nombre]);
            }

            if (in_array($nombre, self::SALTEADOS, true)) {
                continue;
            }

            if (in_array($nombre, self::CONTENEDORES, true)) {
                if (++$this->nodos > self::TOPE_NODOS) {
                    throw new \InvalidArgumentException('El SVG tiene demasiadas figuras');
                }

                $adentro = $this->recorrer($hijo, $profundidad + 1);

                if ($nombre === 'a' || $nombre === 'switch') {
                    $salida .= $adentro;
                    continue;
                }

                $atributos = $this->atributosPermitidos($hijo);
                $salida .= '<g' . $atributos . '>' . $adentro . '</g>';
                continue;
            }

            if (in_array($nombre, self::FIGURAS, true)) {
                if (++$this->nodos > self::TOPE_NODOS) {
                    throw new \InvalidArgumentException('El SVG tiene demasiadas figuras');
                }

                $atributos = $this->atributosPermitidos($hijo);
                $salida .= '<' . $nombre . $atributos . '/>';
                continue;
            }

            // Cualquier otro elemento desconocido en el namespace de SVG se
            // ignora en silencio: no está en la lista de figuras reales, así
            // que no dibuja nada por sí mismo.
        }

        return $salida;
    }

    private function atributosPermitidos(\DOMElement $el): string
    {
        $pares = [];

        // El fill/stroke de un style="..." inline (típico de Inkscape:
        // style="fill:none;stroke:#000;stroke-width:2") se rescata antes de
        // que se descarte el atributo style entero.
        [$fillDeEstilo, $strokeDeEstilo] = $this->leerEstiloInline($el->getAttribute('style'));

        foreach (self::ATRIBUTOS_PERMITIDOS as $nombre) {
            if (!$el->hasAttribute($nombre)) {
                continue;
            }

            $valor = $this->valorValidado($nombre, $el->getAttribute($nombre));
            if ($valor !== null) {
                $pares[$nombre] = $valor;
            }
        }

        $fill = $el->hasAttribute('fill') ? $el->getAttribute('fill') : $fillDeEstilo;
        if ($fill !== null && strtolower(trim($fill)) === 'none') {
            $pares['fill'] = 'none';
        }

        $stroke = $el->hasAttribute('stroke') ? $el->getAttribute('stroke') : $strokeDeEstilo;
        if ($stroke !== null && strtolower(trim($stroke)) !== 'none' && trim($stroke) !== '') {
            $pares['stroke'] = 'currentColor';
        }

        $out = '';
        foreach ($pares as $nombre => $valor) {
            $out .= ' ' . $nombre . '="' . htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') . '"';
        }

        return $out;
    }

    /**
     * @return array{0:?string,1:?string} [fill, stroke]
     */
    private function leerEstiloInline(string $style): array
    {
        if (trim($style) === '') {
            return [null, null];
        }

        $fill = null;
        $stroke = null;

        foreach (explode(';', $style) as $declaracion) {
            $partes = explode(':', $declaracion, 2);
            if (count($partes) !== 2) {
                continue;
            }

            $propiedad = strtolower(trim($partes[0]));
            $valor = trim($partes[1]);

            if ($propiedad === 'fill') {
                $fill = $valor;
            } elseif ($propiedad === 'stroke') {
                $stroke = $valor;
            }
        }

        return [$fill, $stroke];
    }

    private function valorValidado(string $nombre, string $valor): ?string
    {
        $valor = trim($valor);

        if ($valor === '') {
            return null;
        }

        if ($nombre === 'd') {
            if (!preg_match('/^[MmZzLlHhVvCcSsQqTtAa0-9.,+\-eE\s]+$/', $valor)) {
                return null;
            }

            return $this->redondearNumeros($valor);
        }

        if ($nombre === 'points') {
            if (!preg_match('/^[0-9.,+\-eE\s]+$/', $valor)) {
                return null;
            }

            return $this->redondearNumeros($valor);
        }

        if ($nombre === 'transform') {
            if (!preg_match('/^(\s*(matrix|translate|scale|rotate|skewX|skewY)\s*\([0-9.,+\-eE\s]*\)\s*)+$/', $valor)) {
                return null;
            }

            return $this->redondearNumeros($valor);
        }

        if (in_array($nombre, ['fill-rule', 'clip-rule'], true)) {
            return in_array($valor, ['nonzero', 'evenodd'], true) ? $valor : null;
        }

        if (in_array($nombre, ['stroke-linecap'], true)) {
            return in_array($valor, ['butt', 'round', 'square'], true) ? $valor : null;
        }

        if (in_array($nombre, ['stroke-linejoin'], true)) {
            return in_array($valor, ['miter', 'round', 'bevel'], true) ? $valor : null;
        }

        // Números simples (x, y, width, height, rx, ry, cx, cy, r, x1, y1,
        // x2, y2, stroke-width, stroke-miterlimit).
        if (!preg_match('/^-?[0-9]*\.?[0-9]+$/', $valor)) {
            return null;
        }

        return $this->redondearNumeros($valor);
    }

    /**
     * Recorta la cantidad de decimales de los números de un valor de
     * atributo. Un export de Figma trae coordenadas con 10+ decimales que no
     * aportan nada a un dibujo de pocos milímetros y son buena parte de por
     * qué un path no entra bajo el tope de tamaño saneado.
     */
    private function redondearNumeros(string $valor): string
    {
        return preg_replace_callback('/-?\d+\.\d+/', static function (array $m): string {
            return rtrim(rtrim(number_format((float) $m[0], 3, '.', ''), '0'), '.');
        }, $valor) ?? $valor;
    }
}
