<?php

namespace App\Support;

/**
 * Arma, valida y reconoce el token del enlace público de un lote (ver "Link
 * para la grabadora" en CLAUDE.md).
 *
 * Es opaco a propósito, igual que FormatoSerial: no lleva el lote ni el
 * cliente adentro -eso vive en qr_enlaces- así que un carácter mal tipeado no
 * puede caer "por casualidad" en otro lote existente: sólo puede dar 404.
 *
 * Las letras se sortean con random_int, que usa el generador criptográfico
 * del sistema -mismo motivo que FormatoSerial.
 *
 * **7 símbolos de un alfabeto de 30 son ~34 bits** (30^7 ≈ 2,19e10), no los
 * ~98 bits de un token largo: el largo lo fija la forma pedida para el link
 * (qr.example.com/WEQEWRW), que se dicta por teléfono y se retipea desde un
 * papel. Con 100 enlaces activos, acertar uno al azar sale 4,6e-9 por intento
 * -unos 219 millones de requests para esperar un acierto, semanas de tráfico
 * continuo y ruidoso-, y del otro lado hay los SVG de una tanda y el nombre
 * de un cliente. Es un margen aceptable, pero ya no es "no se enumera": por
 * eso SerialController::noEncontrado() retarda cada 404. Si algún día hubiera
 * decenas de miles de enlaces activos, esto es lo primero a revisar.
 */
class FormatoEnlace
{
    /** Cantidad de símbolos del token. */
    public const LARGO = 7;

    /**
     * Sin 0/O, 1/I/L y U: los pares que se confunden al leer o al tipear a
     * mano. Son 30 símbolos.
     */
    private const ALFABETO = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * El mismo conjunto que ALFABETO, escrito como clase de caracteres. Las
     * dos formas tienen que cambiar juntas; están separadas porque generar()
     * necesita indexar por posición y los regex necesitan rangos.
     *
     * De acá salen esValido() y deRuta(), en vez de repetir el patrón literal
     * en cada uno -que es justo lo que se le escapó a FormatoSerial. La
     * tercera copia, el CHECK de qr_enlaces en db/schema.sql, es inevitable.
     */
    private const CLASE = '[2-9A-HJKMNP-TV-Z]';

    public static function generar(): string
    {
        $ultima = strlen(self::ALFABETO) - 1;
        $token = '';

        for ($i = 0; $i < self::LARGO; $i++) {
            $token .= self::ALFABETO[random_int(0, $ultima)];
        }

        return $token;
    }

    public static function esValido(string $token): bool
    {
        return (bool) preg_match('/^' . self::CLASE . '{' . self::LARGO . '}$/', $token);
    }

    /**
     * Reconoce el link público en la raíz del dominio: '/WEQEWRW' → 'WEQEWRW'.
     * null si el path no tiene forma de token -y entonces no es este link.
     *
     * Lo usan el guard y el router de public/index.php, que son los dos que
     * tienen que coincidir exactamente en qué path es público.
     *
     * **Case-insensitive**, y devuelve el token ya normalizado a mayúsculas:
     * el link se retipea desde un papel o se reenvía por WhatsApp, así que
     * qr.example.com/weqewrw tiene que llevar al mismo lado que /WEQEWRW. El
     * path de una URL es sensible a mayúsculas por RFC, pero acá el path
     * entero es un identificador nuestro y podemos decidir que no lo sea.
     */
    public static function deRuta(string $path): ?string
    {
        $patron = '#^/(' . self::CLASE . '{' . self::LARGO . '})$#i';

        return preg_match($patron, $path, $coincidencia)
            ? self::normalizar($coincidencia[1])
            : null;
    }

    /**
     * El link que se le pasa a la grabadora: `{base}/{token}`. Es el único
     * lugar donde se concatenan las dos partes -mismo criterio que
     * FormatoSerial::url() con el serial.
     *
     * **$base sale de QR_BASE_URL, no del dominio por el que se entró al
     * panel.** El link tiene que decir `qr.example.com` siempre, aunque se lo
     * copie desde el subdominio de EasyPanel: es lo que se le dicta a un
     * tercero, y no puede cambiar según quién lo copie. La contrapartida es
     * que el link vale lo que valga esa variable — igual que la URL que queda
     * embebida en el QR impreso, que sale de la misma.
     *
     * A diferencia de FormatoSerial::url(), la base **no** se pasa a
     * mayúsculas: ese truco existe para que la URL entre en el modo
     * alfanumérico del QR, y este link no se codifica en ningún código.
     *
     * Con $base vacía devuelve `/TOKEN`, relativo: el navegador lo resuelve
     * contra el origen actual, así que el botón "Copiar link" sigue dando algo
     * usable en desarrollo, donde QR_BASE_URL puede no estar configurada.
     */
    public static function url(string $base, string $token): string
    {
        return $base . '/' . $token;
    }

    /**
     * Normaliza lo que llega en la URL. Sólo mayúsculas y recorte de
     * espacios: no se mapean O→0 ni I→1 como en Crockford real, porque esos
     * caracteres no existen en ningún token emitido y "corregirlos" sería
     * inventar un token que nadie sorteó.
     */
    public static function normalizar(string $token): string
    {
        return strtoupper(trim($token));
    }
}
