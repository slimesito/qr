<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Encoder de códigos QR en PHP puro: texto → matriz de módulos.
 *
 * Escrito para este proyecto (no es una librería vendorizada, así que no hay
 * licencia de terceros que arrastrar). Cubre lo que la app necesita y nada más:
 *
 *   - modo alfanumérico y modo byte, eligiendo solo el que menos ocupe,
 *   - nivel de corrección de errores H (~30%), necesario porque el centro del
 *     código se reserva para el logo (ver QrSvg) y hay que poder perder ese
 *     bloque de módulos sin perder el código,
 *   - versiones 1 a 10.
 *
 * **Por qué dos modos.** El modo byte gasta 8 bits por carácter; el
 * alfanumérico mete dos caracteres en 11 bits (5,5 por carácter, un 31% menos),
 * pero sólo admite 45 símbolos: dígitos, letras **mayúsculas** y `$%*+-./: `.
 * Una URL entra en ese juego siempre que vaya en mayúsculas — y puede ir, porque
 * el esquema y el host son insensibles a mayúsculas (RFC 3986 §3.1 y §3.2.2) y
 * el serial ya es `QR` + 6 letras mayúsculas. Menos bits es una versión más
 * chica, y una versión más chica son **módulos más grandes en la misma placa**,
 * que es lo que decide a qué distancia se puede escanear.
 *
 * Con la URL de EasyPanel (46 caracteres) eso es versión 4 (33×33) en vez de
 * versión 6 (41×41): a 25 mm de placa, el módulo pasa de ~0,51 mm a ~0,61 mm.
 * El modo se elige solo en modo(): si el texto tiene algo fuera del juego
 * alfanumérico se cae a byte y el QR sale como antes, sin romperse.
 *
 * Referencia: ISO/IEC 18004. Los números de las tablas de abajo salen de ahí.
 */
class QrMatrix
{
    /** Versión máxima soportada. Más arriba haría falta ampliar las tablas. */
    private const VERSION_MAX = 10;

    /** Indicadores de modo de la norma. */
    private const MODO_ALFA = 'alfa';
    private const MODO_BYTE = 'byte';

    /**
     * Los 45 símbolos del modo alfanumérico, **en el orden que define la
     * norma**: la posición de cada carácter es su valor al codificar, así que
     * esta cadena no se puede reordenar.
     */
    private const CHARSET_ALFA = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /**
     * Por versión, para nivel H:
     * [codewords de corrección por bloque, bloques grupo 1, datos por bloque
     *  del grupo 1, bloques grupo 2, datos por bloque del grupo 2].
     */
    private const BLOQUES = [
        1  => [17, 1,  9, 0,  0],
        2  => [28, 1, 16, 0,  0],
        3  => [22, 2, 13, 0,  0],
        4  => [16, 4,  9, 0,  0],
        5  => [22, 2, 11, 2, 12],
        6  => [28, 4, 15, 0,  0],
        7  => [26, 4, 13, 1, 14],
        8  => [26, 4, 14, 2, 15],
        9  => [24, 4, 12, 4, 13],
        10 => [28, 6, 15, 2, 16],
    ];

    /** Coordenadas de los centros de los patrones de alineación, por versión. */
    private const ALINEACION = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Tablas exp/log de GF(256), se construyen una sola vez. */
    private static array $exp = [];
    private static array $log = [];

    /**
     * Codifica un texto y devuelve la matriz de módulos: 1 = oscuro, 0 = claro.
     * No incluye zona de silencio; de eso se ocupa quien renderiza.
     *
     * @return array<int, array<int, int>>
     */
    public static function codificar(string $texto): array
    {
        if ($texto === '') {
            throw new InvalidArgumentException('No se puede generar un QR de texto vacío');
        }

        self::iniciarGf();

        $modo = self::modo($texto);
        $version = self::elegirVersion($texto, $modo);
        $codewords = self::entrelazar(self::codewordsDeDatos($texto, $version, $modo), $version);

        [$matriz, $reservado] = self::construirBase($version);
        $matriz = self::colocarDatos($matriz, $reservado, $codewords);

        // Se prueban las 8 máscaras y gana la de menor penalización. Es lo que
        // manda la norma: una máscara mala deja zonas uniformes que confunden
        // al lector.
        $mejor = null;
        $mejorPenalizacion = PHP_INT_MAX;

        for ($mascara = 0; $mascara < 8; $mascara++) {
            $candidata = self::aplicarMascara($matriz, $reservado, $mascara);
            $candidata = self::colocarFormato($candidata, $mascara);

            if ($version >= 7) {
                $candidata = self::colocarVersion($candidata, $version);
            }

            $penalizacion = self::penalizacion($candidata);
            if ($penalizacion < $mejorPenalizacion) {
                $mejorPenalizacion = $penalizacion;
                $mejor = $candidata;
            }
        }

        return $mejor;
    }

    // ==================== SELECCIÓN DE MODO Y VERSIÓN ====================

    /**
     * El modo más compacto que admita este texto. Alfanumérico entra en menos
     * bits, pero sólo acepta los 45 símbolos de CHARSET_ALFA; cualquier otra
     * cosa (una minúscula, un guion bajo, una tilde) obliga a byte.
     */
    private static function modo(string $texto): string
    {
        for ($i = 0; $i < strlen($texto); $i++) {
            if (strpos(self::CHARSET_ALFA, $texto[$i]) === false) {
                return self::MODO_BYTE;
            }
        }

        return self::MODO_ALFA;
    }

    private static function elegirVersion(string $texto, string $modo): int
    {
        $bits = self::bitsDeContenido($texto, $modo);

        for ($version = 1; $version <= self::VERSION_MAX; $version++) {
            $disponibles = self::totalCodewordsDeDatos($version) * 8
                - 4 - self::bitsContador($version, $modo);

            if ($bits <= $disponibles) {
                return $version;
            }
        }

        throw new InvalidArgumentException(
            "El texto no entra en un QR versión " . self::VERSION_MAX
            . " (se pidieron " . strlen($texto) . " caracteres en modo {$modo})"
        );
    }

    /** Bits que ocupa el contenido, sin contar indicador de modo ni contador. */
    private static function bitsDeContenido(string $texto, string $modo): int
    {
        $largo = strlen($texto);

        if ($modo === self::MODO_BYTE) {
            return $largo * 8;
        }

        // Los caracteres van de a pares en 11 bits; si sobra uno impar al
        // final, se codifica solo en 6.
        return 11 * intdiv($largo, 2) + 6 * ($largo % 2);
    }

    /**
     * Ancho del contador de caracteres. Depende del modo y del tramo de
     * versión; acá sólo llegan versiones 1 a 10.
     */
    private static function bitsContador(int $version, string $modo): int
    {
        if ($modo === self::MODO_BYTE) {
            return $version >= 10 ? 16 : 8;
        }

        return $version >= 10 ? 11 : 9;
    }

    private static function totalCodewordsDeDatos(int $version): int
    {
        [, $g1Bloques, $g1Datos, $g2Bloques, $g2Datos] = self::BLOQUES[$version];

        return $g1Bloques * $g1Datos + $g2Bloques * $g2Datos;
    }

    // ==================== DATOS Y CORRECCIÓN DE ERRORES ====================

    /**
     * Texto → codewords de datos, ya con terminador y relleno.
     *
     * @return array<int, int>
     */
    private static function codewordsDeDatos(string $texto, int $version, string $modo): array
    {
        $capacidadBits = self::totalCodewordsDeDatos($version) * 8;

        $bits = $modo === self::MODO_BYTE ? '0100' : '0010'; // indicador de modo
        $bits .= str_pad(decbin(strlen($texto)), self::bitsContador($version, $modo), '0', STR_PAD_LEFT);
        $bits .= self::codificarContenido($texto, $modo);

        // Terminador: hasta 4 ceros, o menos si ya no queda lugar.
        $bits .= str_repeat('0', min(4, $capacidadBits - strlen($bits)));

        // Completar el último byte.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }

        // Relleno alternando 11101100 / 00010001 hasta llenar la capacidad.
        $relleno = [0xEC, 0x11];
        $i = 0;
        while (strlen($bits) < $capacidadBits) {
            $bits .= str_pad(decbin($relleno[$i % 2]), 8, '0', STR_PAD_LEFT);
            $i++;
        }

        $codewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $codewords[] = bindec(substr($bits, $i, 8));
        }

        return $codewords;
    }

    /**
     * El contenido como cadena de bits, según el modo.
     *
     * En byte es directo: un carácter, sus 8 bits. En alfanumérico los
     * caracteres van **de a pares**, y el par se combina en un solo número
     * (`45 * primero + segundo`) que ocupa 11 bits: de ahí sale el ahorro
     * frente a los 16 bits que costarían esos dos caracteres en modo byte. Si
     * el texto tiene largo impar, el último carácter va solo en 6 bits.
     */
    private static function codificarContenido(string $texto, string $modo): string
    {
        $bits = '';
        $largo = strlen($texto);

        if ($modo === self::MODO_BYTE) {
            for ($i = 0; $i < $largo; $i++) {
                $bits .= str_pad(decbin(ord($texto[$i])), 8, '0', STR_PAD_LEFT);
            }

            return $bits;
        }

        for ($i = 0; $i + 1 < $largo; $i += 2) {
            $par = 45 * strpos(self::CHARSET_ALFA, $texto[$i])
                 + strpos(self::CHARSET_ALFA, $texto[$i + 1]);
            $bits .= str_pad(decbin($par), 11, '0', STR_PAD_LEFT);
        }

        if ($largo % 2 === 1) {
            $bits .= str_pad(decbin(strpos(self::CHARSET_ALFA, $texto[$largo - 1])), 6, '0', STR_PAD_LEFT);
        }

        return $bits;
    }

    /**
     * Parte los datos en bloques, les calcula la corrección de errores y los
     * intercala en el orden que espera el lector.
     *
     * @param  array<int, int> $codewords
     * @return array<int, int>
     */
    private static function entrelazar(array $codewords, int $version): array
    {
        [$ecPorBloque, $g1Bloques, $g1Datos, $g2Bloques, $g2Datos] = self::BLOQUES[$version];

        $bloquesDatos = [];
        $bloquesEc = [];
        $pos = 0;

        foreach ([[$g1Bloques, $g1Datos], [$g2Bloques, $g2Datos]] as [$cantidad, $largo]) {
            for ($i = 0; $i < $cantidad; $i++) {
                $bloque = array_slice($codewords, $pos, $largo);
                $pos += $largo;

                $bloquesDatos[] = $bloque;
                $bloquesEc[] = self::correccion($bloque, $ecPorBloque);
            }
        }

        $salida = [];

        // Primero los datos, tomando un codeword de cada bloque por vuelta.
        // Los bloques del grupo 1 son más cortos, así que en la última vuelta
        // simplemente no aportan (de ahí el isset).
        $maxDatos = max($g1Datos, $g2Datos);
        for ($i = 0; $i < $maxDatos; $i++) {
            foreach ($bloquesDatos as $bloque) {
                if (isset($bloque[$i])) {
                    $salida[] = $bloque[$i];
                }
            }
        }

        // Después la corrección, que sí tiene el mismo largo en todos los bloques.
        for ($i = 0; $i < $ecPorBloque; $i++) {
            foreach ($bloquesEc as $bloque) {
                $salida[] = $bloque[$i];
            }
        }

        return $salida;
    }

    /**
     * Resto de la división del bloque por el polinomio generador: son los
     * codewords de Reed-Solomon.
     *
     * @param  array<int, int> $datos
     * @return array<int, int>
     */
    private static function correccion(array $datos, int $cantidad): array
    {
        $generador = self::polinomioGenerador($cantidad);
        $resto = array_merge($datos, array_fill(0, $cantidad, 0));
        $largoDatos = count($datos);

        for ($i = 0; $i < $largoDatos; $i++) {
            $coeficiente = $resto[$i];
            if ($coeficiente === 0) {
                continue;
            }

            foreach ($generador as $j => $g) {
                $resto[$i + $j] ^= self::multiplicar($g, $coeficiente);
            }
        }

        return array_slice($resto, $largoDatos);
    }

    /** Producto de (x - α^0)(x - α^1)...(x - α^(grado-1)) en GF(256). */
    private static function polinomioGenerador(int $grado): array
    {
        $poli = [1];

        for ($i = 0; $i < $grado; $i++) {
            $nuevo = array_fill(0, count($poli) + 1, 0);

            foreach ($poli as $j => $coeficiente) {
                $nuevo[$j] ^= $coeficiente;
                $nuevo[$j + 1] ^= self::multiplicar($coeficiente, self::$exp[$i]);
            }

            $poli = $nuevo;
        }

        return $poli;
    }

    /** Tablas de potencias y logaritmos de GF(256) con polinomio 0x11D. */
    private static function iniciarGf(): void
    {
        if (self::$exp !== []) {
            return;
        }

        $valor = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $valor;
            self::$log[$valor] = $i;

            $valor <<= 1;
            if ($valor & 0x100) {
                $valor ^= 0x11D;
            }
        }

        // Se duplica la tabla para poder sumar logaritmos sin tomar módulo.
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function multiplicar(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    // ==================== MATRIZ ====================

    /**
     * Matriz con los patrones fijos ya puestos, más el mapa de qué celdas están
     * reservadas (y por lo tanto no reciben datos ni máscara).
     *
     * @return array{0: array<int, array<int, int>>, 1: array<int, array<int, bool>>}
     */
    private static function construirBase(int $version): array
    {
        $lado = $version * 4 + 17;
        $matriz = array_fill(0, $lado, array_fill(0, $lado, 0));
        $reservado = array_fill(0, $lado, array_fill(0, $lado, false));

        // Patrones de búsqueda (las tres esquinas) con su separador blanco.
        foreach ([[0, 0], [0, $lado - 7], [$lado - 7, 0]] as [$filaBase, $columnaBase]) {
            for ($f = -1; $f <= 7; $f++) {
                for ($c = -1; $c <= 7; $c++) {
                    $fila = $filaBase + $f;
                    $columna = $columnaBase + $c;

                    if ($fila < 0 || $fila >= $lado || $columna < 0 || $columna >= $lado) {
                        continue;
                    }

                    $enPatron = $f >= 0 && $f <= 6 && $c >= 0 && $c <= 6;
                    $oscuro = $enPatron && (
                        $f === 0 || $f === 6 || $c === 0 || $c === 6
                        || ($f >= 2 && $f <= 4 && $c >= 2 && $c <= 4)
                    );

                    $matriz[$fila][$columna] = $oscuro ? 1 : 0;
                    $reservado[$fila][$columna] = true;
                }
            }
        }

        // Patrones de sincronismo: fila y columna 6, alternando.
        for ($i = 8; $i < $lado - 8; $i++) {
            $valor = $i % 2 === 0 ? 1 : 0;
            $matriz[6][$i] = $valor;
            $reservado[6][$i] = true;
            $matriz[$i][6] = $valor;
            $reservado[$i][6] = true;
        }

        // Patrones de alineación, salteando los que pisarían un patrón de búsqueda.
        $centros = self::ALINEACION[$version];
        $cantidad = count($centros);

        for ($i = 0; $i < $cantidad; $i++) {
            for ($j = 0; $j < $cantidad; $j++) {
                $esquina = ($i === 0 && $j === 0)
                    || ($i === 0 && $j === $cantidad - 1)
                    || ($i === $cantidad - 1 && $j === 0);

                if ($esquina) {
                    continue;
                }

                for ($f = -2; $f <= 2; $f++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $fila = $centros[$i] + $f;
                        $columna = $centros[$j] + $c;

                        // Anillo exterior y centro oscuros, anillo interior claro.
                        $matriz[$fila][$columna] = max(abs($f), abs($c)) === 1 ? 0 : 1;
                        $reservado[$fila][$columna] = true;
                    }
                }
            }
        }

        // Módulo siempre oscuro, al lado del patrón inferior izquierdo.
        $matriz[$lado - 8][8] = 1;
        $reservado[$lado - 8][8] = true;

        // Reserva de las dos copias de la información de formato.
        for ($i = 0; $i <= 8; $i++) {
            $reservado[8][$i] = true;
            $reservado[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reservado[8][$lado - 1 - $i] = true;
            $reservado[$lado - 1 - $i][8] = true;
        }

        // Desde la versión 7 hay además información de versión en dos bloques.
        if ($version >= 7) {
            for ($i = 0; $i < 18; $i++) {
                $fila = intdiv($i, 3);
                $columna = $lado - 11 + $i % 3;

                $reservado[$fila][$columna] = true;
                $reservado[$columna][$fila] = true;
            }
        }

        return [$matriz, $reservado];
    }

    /**
     * Recorrido en zigzag desde abajo a la derecha, de a dos columnas,
     * salteando la columna de sincronismo.
     */
    private static function colocarDatos(array $matriz, array $reservado, array $codewords): array
    {
        $lado = count($matriz);

        $bits = '';
        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        $indice = 0;
        $haciaArriba = true;

        for ($columna = $lado - 1; $columna > 0; $columna -= 2) {
            if ($columna === 6) {
                $columna = 5; // la columna 6 es sincronismo, se corre una a la izquierda
            }

            for ($i = 0; $i < $lado; $i++) {
                $fila = $haciaArriba ? $lado - 1 - $i : $i;

                foreach ([$columna, $columna - 1] as $c) {
                    if ($reservado[$fila][$c]) {
                        continue;
                    }

                    // Si los datos se acabaron, el resto queda en claro: la norma
                    // permite terminar con módulos de relleno.
                    $matriz[$fila][$c] = ($indice < strlen($bits) && $bits[$indice] === '1') ? 1 : 0;
                    $indice++;
                }
            }

            $haciaArriba = !$haciaArriba;
        }

        return $matriz;
    }

    private static function aplicarMascara(array $matriz, array $reservado, int $mascara): array
    {
        $lado = count($matriz);

        for ($f = 0; $f < $lado; $f++) {
            for ($c = 0; $c < $lado; $c++) {
                if ($reservado[$f][$c]) {
                    continue;
                }

                if (self::condicionMascara($mascara, $f, $c)) {
                    $matriz[$f][$c] ^= 1;
                }
            }
        }

        return $matriz;
    }

    private static function condicionMascara(int $mascara, int $f, int $c): bool
    {
        switch ($mascara) {
            case 0: return ($f + $c) % 2 === 0;
            case 1: return $f % 2 === 0;
            case 2: return $c % 3 === 0;
            case 3: return ($f + $c) % 3 === 0;
            case 4: return (intdiv($f, 2) + intdiv($c, 3)) % 2 === 0;
            case 5: return ($f * $c) % 2 + ($f * $c) % 3 === 0;
            case 6: return (($f * $c) % 2 + ($f * $c) % 3) % 2 === 0;
            default: return ((($f + $c) % 2) + ($f * $c) % 3) % 2 === 0;
        }
    }

    /** Información de formato: nivel de corrección H + máscara, con BCH(15,5). */
    private static function colocarFormato(array $matriz, int $mascara): array
    {
        $lado = count($matriz);

        $datos = (0b10 << 3) | $mascara; // 10 = nivel H
        $bch = $datos << 10;
        $resto = $bch;

        for ($i = 14; $i >= 10; $i--) {
            if ($resto & (1 << $i)) {
                $resto ^= 0x537 << ($i - 10);
            }
        }

        $formato = ($bch | $resto) ^ 0x5412; // máscara fija de la norma

        // Los 15 bits van dos veces, y en cada copia el orden es el inverso del
        // otro: el bit 0 arranca pegado al patrón superior izquierdo en la copia
        // vertical, y en el extremo opuesto en la horizontal. Espejarlo es un
        // error silencioso — la matriz se ve bien pero ningún lector la abre.
        for ($i = 0; $i < 15; $i++) {
            $bit = ($formato >> $i) & 1;

            // Copia vertical, sobre la columna 8: bits 0-7 arriba (salteando la
            // fila 6, que es sincronismo) y bits 8-14 abajo.
            if ($i < 6) {
                $fila = $i;
            } elseif ($i < 8) {
                $fila = $i + 1;
            } else {
                $fila = $lado - 15 + $i;
            }
            $matriz[$fila][8] = $bit;

            // Copia horizontal, sobre la fila 8: bits 0-7 a la derecha y
            // bits 8-14 a la izquierda (salteando la columna 6).
            if ($i < 8) {
                $columna = $lado - 1 - $i;
            } elseif ($i === 8) {
                $columna = 7;
            } else {
                $columna = 14 - $i;
            }
            $matriz[8][$columna] = $bit;
        }

        return $matriz;
    }

    /** Información de versión (sólo 7 en adelante), con BCH(18,6). */
    private static function colocarVersion(array $matriz, int $version): array
    {
        $lado = count($matriz);

        $bch = $version << 12;
        $resto = $bch;

        for ($i = 17; $i >= 12; $i--) {
            if ($resto & (1 << $i)) {
                $resto ^= 0x1F25 << ($i - 12);
            }
        }

        $datos = $bch | $resto;

        for ($i = 0; $i < 18; $i++) {
            $bit = ($datos >> $i) & 1;
            $fila = intdiv($i, 3);
            $columna = $lado - 11 + $i % 3;

            $matriz[$fila][$columna] = $bit;
            $matriz[$columna][$fila] = $bit;
        }

        return $matriz;
    }

    // ==================== PENALIZACIÓN ====================

    /**
     * Las cuatro reglas de la norma para elegir máscara. Menos es mejor.
     */
    private static function penalizacion(array $matriz): int
    {
        $lado = count($matriz);
        $total = 0;

        $filas = [];
        $columnas = array_fill(0, $lado, '');

        foreach ($matriz as $fila => $celdas) {
            $filas[$fila] = implode('', $celdas);
            foreach ($celdas as $columna => $valor) {
                $columnas[$columna] .= $valor;
            }
        }

        // Regla 1: rachas de 5 o más módulos del mismo color.
        foreach (array_merge($filas, $columnas) as $linea) {
            $total += self::penalizacionRachas($linea);
        }

        // Regla 2: bloques de 2x2 del mismo color.
        for ($f = 0; $f < $lado - 1; $f++) {
            for ($c = 0; $c < $lado - 1; $c++) {
                $v = $matriz[$f][$c];
                if ($v === $matriz[$f][$c + 1] && $v === $matriz[$f + 1][$c] && $v === $matriz[$f + 1][$c + 1]) {
                    $total += 3;
                }
            }
        }

        // Regla 3: secuencias que se parecen a un patrón de búsqueda y pueden
        // hacer que el lector se confunda de referencia.
        foreach (array_merge($filas, $columnas) as $linea) {
            $total += 40 * (substr_count($linea, '10111010000') + substr_count($linea, '00001011101'));
        }

        // Regla 4: desbalance entre módulos oscuros y claros.
        $oscuros = 0;
        foreach ($filas as $linea) {
            $oscuros += substr_count($linea, '1');
        }

        $porcentaje = $oscuros * 100 / ($lado * $lado);
        $total += intdiv((int) floor(abs($porcentaje - 50)), 5) * 10;

        return $total;
    }

    private static function penalizacionRachas(string $linea): int
    {
        $total = 0;
        $largo = strlen($linea);
        $racha = 1;

        for ($i = 1; $i < $largo; $i++) {
            if ($linea[$i] === $linea[$i - 1]) {
                $racha++;
                continue;
            }

            if ($racha >= 5) {
                $total += 3 + ($racha - 5);
            }
            $racha = 1;
        }

        if ($racha >= 5) {
            $total += 3 + ($racha - 5);
        }

        return $total;
    }
}
