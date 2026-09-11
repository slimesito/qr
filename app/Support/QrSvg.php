<?php

namespace App\Support;

/**
 * Matriz de módulos → SVG.
 *
 * Se eligió SVG y no PNG a propósito: es vectorial, así que la imprenta lo puede
 * agrandar a cualquier tamaño sin que pixele, y no hace falta la extensión GD
 * (que no está instalada en la imagen).
 */
class QrSvg
{
    /** Módulos en blanco alrededor del código. La norma pide 4 como mínimo. */
    private const ZONA_SILENCIO = 4;

    /**
     * Lado del hueco central reservado al logo, **en módulos y por versión**.
     *
     * No es una fracción fija del lado: es el hueco más grande que cada versión
     * tolera sin comprometer la lectura, y eso no escala parejo con el tamaño
     * del código. El número sale de un cálculo, no de una estimación visual —
     * ver "Logo en el centro" en CLAUDE.md para la derivación completa.
     *
     * El límite real no es "qué porcentaje del área tapa el logo" sino
     * **cuántos codewords quedan rotos en el bloque Reed-Solomon más golpeado**.
     * Un logo encima del código produce *errores*, no *borrones*: el lector no
     * sabe que esos módulos están tapados, los lee como blanco o negro y se
     * come un valor equivocado. Reed-Solomon corrige la mitad de errores que de
     * borrones, así que el tope por bloque es floor(ec/2), y el entrelazado
     * reparte un cuadrado central de forma despareja: algunos bloques se llevan
     * casi todo el daño mucho antes de que el promedio se acerque al límite.
     *
     * Acá se usa como máximo **el 75% de ese presupuesto**. El 25% restante no
     * es cautela abstracta: estas placas se graban y se pegan a la intemperie,
     * y la corrección que gasta el logo es corrección que después no está para
     * la suciedad, los rayones y la mala iluminación.
     *
     *   ver  lado   hueco  peor bloque  logo (% del área)
     *    3   29×29    9       8/11  73%       6,5%
     *    4   33×33   11       6/8   75%       8,1%
     *    5   37×37   11       6/11  55%       6,5%
     *    6   41×41   13       7/14  50%       7,7%
     *    7   45×45   17       9/13  69%      11,7%
     *
     * Desde la versión 7 hay además un patrón de alineación **exactamente en el
     * centro**, que cualquier hueco tapa. Es un patrón de función: el lector lo
     * usa para enderezar la perspectiva y Reed-Solomon no lo cubre. No hay
     * forma de evitarlo con el logo centrado, así que en 7-10 el hueco se
     * elige igual por presupuesto, pero conviene saber que esas versiones
     * llegan al lector con una referencia geométrica menos.
     */
    private const HUECO_POR_VERSION = [
        1  => 5,   // 21×21 — sin margen para más, es el piso
        2  => 7,   // 25×25
        3  => 9,   // 29×29
        4  => 11,  // 33×33
        5  => 11,  // 37×37
        6  => 13,  // 41×41  ← la URL de EasyPanel de hoy
        7  => 17,  // 45×45  ← desde acá el hueco tapa el patrón de alineación central
        8  => 17,  // 49×49
        9  => 19,  // 53×53
        10 => 21,  // 57×57
    ];

    /** Piso del hueco central, en módulos, para versiones chicas. */
    private const LOGO_MINIMO = 5;

    /** Aire entre el borde del logo y los módulos vecinos, en módulos. */
    private const LOGO_MARGEN = 0.8;

    /**
     * @param int $lado Lado de la imagen en píxeles. Es sólo el tamaño sugerido:
     *                  el viewBox permite escalarla sin pérdida.
     * @param ?QrLogo $logo Logo del cliente. Con null se usa el placeholder
     *                      (QrLogo::porDefecto()), que es lo que llevan los
     *                      clientes que todavía no subieron uno propio.
     */
    public static function render(string $texto, int $lado = 256, ?QrLogo $logo = null): string
    {
        $matriz = QrMatrix::codificar($texto);
        $total = count($matriz) + self::ZONA_SILENCIO * 2;
        [$desde, $hasta] = self::hueco($matriz);

        $lado = max(1, $lado);
        $alt = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');

        // shape-rendering=crispEdges evita que el navegador suavice los bordes
        // y deje los módulos borrosos, que es lo que rompe la lectura. El logo
        // se dibuja aparte, con geometricPrecision: es lo correcto para sus
        // curvas, que con crispEdges quedarían dentadas.
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '"'
            . ' width="' . $lado . '" height="' . $lado . '"'
            . ' shape-rendering="crispEdges" role="img" aria-label="' . $alt . '">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>'
            . '<path fill="#000000" stroke="none" d="' . self::path($matriz, $desde, $hasta) . '"/>'
            . self::logo($total, $hasta - $desde, $logo ?? QrLogo::porDefecto(), true)
            . '</svg>';
    }

    /**
     * Variante para grabadoras láser (LightBurn, RDWorks y compañía), que
     * difiere de la de pantalla en tres cosas, las tres necesarias:
     *
     * 1. **Milímetros de verdad** en width/height. Sin unidad, cada programa
     *    supone su propio DPI y el código entra con el tamaño equivocado.
     * 2. **Sin el rectángulo blanco de fondo.** En pantalla da contraste, pero
     *    la grabadora lo importa como una figura más y termina grabando —o
     *    peor, cortando— el cuadrado entero.
     * 3. **Sin atributos de presentación de pantalla** (shape-rendering, role,
     *    aria-label): no los usa nadie del otro lado.
     *
     * El logo no está en la lista: va en negro acá y también en pantalla (ver
     * QrLogo::COLOR), así que las dos salidas comparten el mismo dibujo entero.
     *
     * La zona de silencio va incluida en el tamaño pedido: el archivo es un
     * cuadrado de $mm de lado y el código ocupa el centro, con el margen claro
     * que la norma exige alrededor.
     */
    public static function paraGrabado(string $texto, float $mm = 25.0, ?QrLogo $logo = null): string
    {
        [$contenido, $total] = self::grabado($texto, $logo);
        $lado = self::medida(max(0.1, $mm));

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '"'
            . ' width="' . $lado . 'mm" height="' . $lado . 'mm">'
            . $contenido
            . '</svg>';
    }

    /**
     * El mismo código de paraGrabado(), pero como un `<g>` ya colocado y
     * escalado dentro de una hoja más grande **cuyas unidades de usuario son
     * milímetros** (es decir, `viewBox="0 0 210 297"` sobre `width="210mm"`).
     *
     * Es lo que arma la hoja de grabado: varias placas en un solo archivo, cada
     * una con la medida de su propio lote. Se coloca con un `<g transform>` y no
     * con un `<svg>` anidado por el mismo motivo por el que el logo tampoco usa
     * uno (ver logo() más abajo): del otro lado hay parsers simples, y un `<g>`
     * con translate/scale explícito es exactamente lo que entienden. La
     * geometría de adentro queda en coordenadas de módulo -enteras y compartidas
     * por los N códigos de la hoja-, que es además lo que mantiene el archivo
     * corto: emitir cada path ya convertido a milímetros llenaría de decimales
     * los cientos de nodos de cada código.
     *
     * @param float $x Esquina superior izquierda, en mm de la hoja.
     * @param float $y Ídem. La zona de silencio va adentro de $mm, así que las
     *                 coordenadas son las del cuadrado completo de la placa.
     */
    public static function enHoja(string $texto, float $mm, ?QrLogo $logo, float $x, float $y): string
    {
        [$contenido, $total] = self::grabado($texto, $logo);

        // 5 decimales: a 200 mm de lado, el error acumulado sobre un código de
        // 57 módulos queda muy por debajo del micrón.
        $escala = round(max(0.1, $mm) / $total, 5);

        return '<g transform="translate(' . self::medida($x) . ' ' . self::medida($y) . ')'
            . ' scale(' . $escala . ')">' . $contenido . '</g>';
    }

    /**
     * Las figuras de un código para grabado -sin el `<rect>` de fondo y sin
     * atributos de pantalla-, en coordenadas de módulo.
     * Es el cuerpo que comparten el archivo suelto (paraGrabado) y la hoja
     * completa (enHoja): las dos salidas tienen que ser el mismo dibujo, sólo
     * cambia dónde se apoya.
     *
     * @return array{0: string, 1: int} markup y lado total en módulos
     */
    private static function grabado(string $texto, ?QrLogo $logo): array
    {
        $matriz = QrMatrix::codificar($texto);
        $total = count($matriz) + self::ZONA_SILENCIO * 2;
        [$desde, $hasta] = self::hueco($matriz);

        return [
            // stroke="none" explícito: es el valor por omisión de SVG, pero los
            // importadores de láser que suponen un contorno donde no lo hay
            // terminan cortando el borde de cada figura además de grabarla.
            '<path fill="#000000" stroke="none" d="' . self::path($matriz, $desde, $hasta) . '"/>'
            . self::logo($total, $hasta - $desde, $logo ?? QrLogo::porDefecto(), false),
            $total,
        ];
    }

    /**
     * Milímetros con hasta dos decimales y sin ceros de relleno: los importadores
     * de láser leen el número tal cual, así que "25" es preferible a "25.00".
     */
    public static function medida(float $mm): string
    {
        // number_format siempre deja dos decimales, así que el rtrim del punto
        // nunca se come el número entero: "25.00" → "25", "0.00" → "0".
        return rtrim(rtrim(number_format($mm, 2, '.', ''), '0'), '.');
    }

    /**
     * Rango [desde, hasta) de filas/columnas, en coordenadas de matriz (sin
     * zona de silencio), reservado para el logo. Siempre impar y centrado,
     * porque el lado de la matriz también es impar: así el hueco cae simétrico
     * sobre la grilla en vez de quedar corrido un módulo hacia un lado.
     *
     * El tamaño sale de HUECO_POR_VERSION, que ya viene calculado contra el
     * presupuesto de corrección de errores de cada versión. La versión se
     * deduce del lado de la matriz (lado = versión * 4 + 17), que es la misma
     * cuenta que hace el encoder al revés.
     *
     * @param array<int, array<int, int>> $matriz
     * @return array{0: int, 1: int}
     */
    private static function hueco(array $matriz): array
    {
        $lado = count($matriz);
        $version = intdiv($lado - 17, 4);

        // Una versión fuera de la tabla no debería llegar acá (el encoder sólo
        // emite 1 a 10), pero si algún día se amplía y alguien se olvida de
        // esta tabla, el piso es lo seguro: un logo chico se lee siempre.
        $hueco = max(self::LOGO_MINIMO, self::HUECO_POR_VERSION[$version] ?? self::LOGO_MINIMO);

        $desde = intdiv($lado - $hueco, 2);

        return [$desde, $desde + $hueco];
    }

    /**
     * Matriz → un único path con la zona de silencio ya sumada al origen.
     *
     * Los módulos oscuros no se emiten como figuras sueltas ni como
     * rectángulos fusionados: se hace la **unión geométrica real** de todas las
     * celdas oscuras y se dibuja el contorno de cada mancha resultante. El path
     * queda con **un nodo por cada cambio real de dirección y ninguno más**: un
     * lado recto del patrón de búsqueda es un único segmento, sin los vértices
     * intermedios que dejaba la grilla de módulos original.
     *
     * Las razones son las de siempre, más una tercera:
     *
     * 1. El archivo se acorta —importa en la hoja de 500 QR y en el .zip sin
     *    comprimir.
     * 2. Desaparecen las costuras entre figuras pegadas, que la grabadora sí
     *    marca en el material porque rellena figura por figura. Con la unión no
     *    quedan figuras pegadas en absoluto: cada mancha es un solo contorno.
     * 3. Los nodos redundantes sobre un tramo recto hacen frenar el cabezal en
     *    cada uno. Un lado del patrón de búsqueda con 7 nodos y uno con 2 se
     *    graban distinto aunque la geometría sea idéntica.
     *
     * La reducción es **topológicamente exacta**: no se aproxima, no se
     * redondea, no se desplaza ninguna esquina. Sólo se descartan los vértices
     * cuyo nodo anterior y siguiente caen sobre la misma recta. Las esquinas
     * reales conservan sus coordenadas enteras originales.
     *
     * Los huecos internos —el anillo blanco del patrón de búsqueda es el caso
     * evidente— salen como subpaths propios, con orientación **opuesta** a la
     * del contorno que los rodea (ver aristas()). Así el dibujo es correcto
     * tanto con `nonzero` como con `evenodd`, que es justo lo que hace falta
     * cuando del otro lado hay un importador de láser del que no se sabe qué
     * regla de relleno aplica.
     *
     * El rango [$desde, $hasta) —filas y columnas por igual, el hueco es
     * cuadrado— se trata como si fueran módulos claros: es lo que reserva el
     * centro para el logo. No se pinta nada encima; el módulo directamente no
     * se emite, que es lo único que también funciona en el SVG de grabado
     * (ahí no hay blanco: sólo lo que el path dibuja se graba).
     *
     * @param array<int, array<int, int>> $matriz
     */
    private static function path(array $matriz, int $desde, int $hasta): string
    {
        $salidas = self::aristas(self::oscuros($matriz, $desde, $hasta), count($matriz));

        $d = '';
        foreach (array_keys($salidas) as $clave) {
            // Un mismo vértice puede pertenecer a dos contornos distintos
            // (dos manchas que se tocan sólo en esa esquina), así que se vuelve
            // a entrar hasta agotar sus aristas.
            while ($salidas[$clave] !== []) {
                $d .= self::contorno($salidas, $clave);
            }
        }

        return $d;
    }

    /**
     * Celdas oscuras de la matriz, con el hueco del logo ya apagado. Se apaga
     * acá una sola vez para que el trazado de contornos no tenga que volver a
     * preguntarse por él en cada celda que mira.
     *
     * @param array<int, array<int, int>> $matriz
     * @return array<int, array<int, bool>>
     */
    private static function oscuros(array $matriz, int $desde, int $hasta): array
    {
        $oscuros = [];
        foreach ($matriz as $fila => $celdas) {
            $enHueco = $fila >= $desde && $fila < $hasta;

            foreach ($celdas as $columna => $valor) {
                $oscuros[$fila][$columna] =
                    $valor === 1 && !($enHueco && $columna >= $desde && $columna < $hasta);
            }
        }

        return $oscuros;
    }

    /**
     * Aristas de frontera de la unión, dirigidas y agrupadas por vértice de
     * partida.
     *
     * De cada celda oscura se emiten sólo los lados cuyo vecino está apagado
     * (o fuera de la matriz), recorriendo la celda en sentido horario en
     * coordenadas de pantalla: arriba →, derecha ↓, abajo ←, izquierda ↑. Los
     * lados compartidos entre dos celdas oscuras se emitirían en sentidos
     * opuestos, así que directamente no se emiten: eso **es** la unión
     * geométrica, sin ninguna librería de booleanas de por medio.
     *
     * Como el recorrido de cada celda tiene la misma orientación, todas las
     * aristas quedan con lo oscuro del mismo lado. De ahí sale gratis que los
     * contornos exteriores salgan en un sentido y los huecos internos en el
     * contrario, que es lo que hace que el relleno sea correcto con `nonzero`.
     *
     * Las coordenadas ya salen con la zona de silencio sumada.
     *
     * @param array<int, array<int, bool>> $oscuros
     * @return array<string, list<array{0: int, 1: int}>>
     */
    private static function aristas(array $oscuros, int $lado): array
    {
        $salidas = [];

        for ($fila = 0; $fila < $lado; $fila++) {
            for ($columna = 0; $columna < $lado; $columna++) {
                if (!$oscuros[$fila][$columna]) {
                    continue;
                }

                $x = $columna + self::ZONA_SILENCIO;
                $y = $fila + self::ZONA_SILENCIO;

                if (!($oscuros[$fila - 1][$columna] ?? false)) {
                    $salidas[$x . ' ' . $y][] = [$x + 1, $y];
                }
                if (!($oscuros[$fila][$columna + 1] ?? false)) {
                    $salidas[($x + 1) . ' ' . $y][] = [$x + 1, $y + 1];
                }
                if (!($oscuros[$fila + 1][$columna] ?? false)) {
                    $salidas[($x + 1) . ' ' . ($y + 1)][] = [$x, $y + 1];
                }
                if (!($oscuros[$fila][$columna - 1] ?? false)) {
                    $salidas[$x . ' ' . ($y + 1)][] = [$x, $y];
                }
            }
        }

        return $salidas;
    }

    /**
     * Recorre un contorno cerrado desde $clave, consumiendo sus aristas, y
     * devuelve el subpath ya sin nodos colineales.
     *
     * @param array<string, list<array{0: int, 1: int}>> $salidas
     */
    private static function contorno(array &$salidas, string $clave): string
    {
        [$inicioX, $inicioY] = array_map('intval', explode(' ', $clave));

        $x = $inicioX;
        $y = $inicioY;
        $dx = 0;
        $dy = 0;
        $puntos = [];

        do {
            $i = self::siguiente($salidas[$x . ' ' . $y], $x, $y, $dx, $dy);
            [$nx, $ny] = $salidas[$x . ' ' . $y][$i];
            array_splice($salidas[$x . ' ' . $y], $i, 1);

            $dx = $nx - $x;
            $dy = $ny - $y;
            $x = $nx;
            $y = $ny;
            $puntos[] = [$x, $y];
        } while ($x !== $inicioX || $y !== $inicioY);

        return self::subpath($puntos);
    }

    /**
     * Qué arista tomar al llegar a un vértice.
     *
     * Casi siempre hay una sola opción. La excepción es la esquina donde dos
     * manchas se tocan en diagonal: ahí llegan dos aristas y salen otras dos, y
     * elegir mal une los dos contornos en uno solo pinchado en ese punto. Se
     * elige **girar hacia el lado oscuro** (a la derecha del avance, con la Y
     * hacia abajo), que es lo que deja cada mancha con su propio contorno
     * cerrado en vez de un ocho.
     *
     * @param list<array{0: int, 1: int}> $opciones
     */
    private static function siguiente(array $opciones, int $x, int $y, int $dx, int $dy): int
    {
        if (count($opciones) === 1) {
            return 0;
        }

        foreach ($opciones as $i => [$nx, $ny]) {
            if ($nx - $x === -$dy && $ny - $y === $dx) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * Lista cíclica de vértices → subpath cerrado, **sin ningún nodo sobre un
     * tramo recto**.
     *
     * El filtro es la condición de aceptación entera: un vértice sobrevive sólo
     * si su anterior y su siguiente no están sobre la misma horizontal ni sobre
     * la misma vertical, o sea sólo si ahí el contorno cambia de dirección. Como
     * las aristas vienen de una grilla ortogonal, con una sola pasada alcanza:
     * no puede aparecer un nodo colineal nuevo al sacar otro, porque un vértice
     * que quedaba entre dos direcciones distintas las sigue teniendo.
     *
     * Se emite con h/v relativos —el contorno es ortogonal por construcción— que
     * es la forma más corta de escribirlo, y el cierre lo hace la z.
     *
     * @param list<array{0: int, 1: int}> $puntos
     */
    private static function subpath(array $puntos): string
    {
        $total = count($puntos);
        $esquinas = [];

        for ($i = 0; $i < $total; $i++) {
            [$ax, $ay] = $puntos[($i + $total - 1) % $total];
            [$bx, $by] = $puntos[$i];
            [$cx, $cy] = $puntos[($i + 1) % $total];

            if (($ax === $bx && $bx === $cx) || ($ay === $by && $by === $cy)) {
                continue;
            }

            $esquinas[] = [$bx, $by];
        }

        [$x, $y] = $esquinas[0];
        $d = 'M' . $x . ' ' . $y;

        for ($i = 1, $n = count($esquinas); $i < $n; $i++) {
            [$nx, $ny] = $esquinas[$i];
            $d .= $nx === $x ? 'v' . ($ny - $y) : 'h' . ($nx - $x);
            $x = $nx;
            $y = $ny;
        }

        return $d . 'z';
    }

    /**
     * El logo, escalado al hueco reservado y centrado en el SVG completo.
     *
     * El logo de un cliente puede tener cualquier viewBox (no sólo el 16×16
     * del placeholder), así que en vez de anidar un <svg viewBox> y dejar que
     * el navegador resuelva preserveAspectRatio="xMidYMid meet", se reproduce
     * esa cuenta a mano en un <g transform="translate() scale()"> — la misma
     * salida que ya se emitía antes de que el logo fuera por cliente.
     *
     * Es deliberado: el mismo markup termina en el .zip de grabado, y los
     * importadores de LightBurn/RDWorks son parsers simples que no
     * necesariamente resuelven un viewBox en un elemento que no es la raíz
     * del documento, ni una palabra clave CSS como currentColor. Un <g> con
     * un transform explícito y colores ya literales es justo lo que ya
     * asumía esta clase (por eso paraGrabado() saca el <rect> de fondo y los
     * atributos de pantalla). El costo es que se pierde el recorte al
     * viewport que un <svg> anidado sí haría: geometría fuera del viewBox
     * declarado por el logo se derrama sobre los módulos vecinos en vez de
     * cortarse. LOGO_MARGEN da algo de aire para eso, y es preferible a que
     * la hoja recorte en pantalla y el grabado no.
     *
     * Va siempre en negro (QrLogo::COLOR), igual que los módulos: en grabado no
     * hay color posible, y en pantalla se usa el mismo para que la vista de
     * control muestre exactamente lo que después sale de la grabadora.
     *
     * @param int  $total   Lado total del SVG (matriz + zona de silencio).
     * @param int  $hueco   Lado del hueco reservado, en módulos.
     * @param bool $pantalla shape-rendering sólo tiene sentido en pantalla;
     *                       en grabado no lo usa nadie del otro lado.
     */
    private static function logo(int $total, int $hueco, QrLogo $logo, bool $pantalla): string
    {
        $lado = max(0.0, $hueco - 2 * self::LOGO_MARGEN);
        $escala = round($lado / max($logo->ancho, $logo->alto), 4);
        $anchoEscalado = round($logo->ancho * $escala, 3);
        $altoEscalado = round($logo->alto * $escala, 3);
        $tx = round(($total - $anchoEscalado) / 2 - $logo->minX * $escala, 3);
        $ty = round(($total - $altoEscalado) / 2 - $logo->minY * $escala, 3);

        // El markup saneado no trae ningún color propio, salvo el token de
        // almacenamiento "currentColor" en los stroke que sobrevivieron al
        // saneo (ver SaneadorSvg): se resuelve acá, al color literal de la
        // salida, y nunca antes.
        $markup = str_replace('currentColor', QrLogo::COLOR, $logo->markup);

        return '<g transform="translate(' . $tx . ' ' . $ty . ') scale(' . $escala . ')" fill="' . QrLogo::COLOR . '"'
            . ($pantalla ? ' shape-rendering="geometricPrecision"' : '')
            . '>' . $markup . '</g>';
    }
}
