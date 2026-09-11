<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Arma y valida el serial de un QR: el prefijo QR más 6 letras.
 *
 *   QRABCDEF   (8 caracteres, largo fijo)
 *
 * El código es **opaco a propósito**: no se puede deducir de él ni el cliente ni
 * la fecha ni qué número de la tanda es. Todo eso vive en las columnas de
 * qr_seriales, no en el string. Un código que se autodescribe deja adivinar los
 * códigos vecinos, y acá la URL del QR es de hecho la única credencial que hay.
 *
 * Las letras se sortean con random_int, que usa el generador criptográfico del
 * sistema: con rand() los códigos serían predecibles a partir de unos pocos.
 */
class FormatoSerial
{
    public const PREFIJO = 'QR';

    /** Cantidad de letras después del prefijo. */
    public const LETRAS = 6;

    /** Largo total, prefijo incluido. */
    public const LARGO = 8;

    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function generar(): string
    {
        $ultima = strlen(self::ALFABETO) - 1;
        $letras = '';

        for ($i = 0; $i < self::LETRAS; $i++) {
            $letras .= self::ALFABETO[random_int(0, $ultima)];
        }

        return self::PREFIJO . $letras;
    }

    /** Mismo criterio que el CHECK de la tabla qr_seriales. */
    public static function esValido(string $serial): bool
    {
        return (bool) preg_match('/^QR[A-Z]{6}$/', $serial);
    }

    /** Normaliza lo que escribe o pega un usuario antes de validarlo. */
    public static function normalizar(string $serial): string
    {
        return strtoupper(trim($serial));
    }

    /** Prefijo que distingue las tandas importadas de las generadas acá. */
    public const PREFIJO_IMPORTACION = 'IMP-';

    /**
     * Etiqueta de la tanda. Como el serial ya no lleva la fecha adentro, el
     * lote es lo único que agrupa lo que se generó de una: es lo que se filtra
     * para imprimir sólo la última tanda y no todo el histórico del cliente.
     */
    public static function lote(DateTimeInterface $momento): string
    {
        return $momento->format('Ymd-Hi');
    }

    /**
     * Etiqueta de una tanda importada.
     *
     * Lleva prefijo propio para que un lote nunca mezcle códigos generados con
     * códigos importados: si compartieran etiqueta, dos importaciones y una
     * generación en el mismo minuto caerían en la misma fila de la tabla y no
     * habría forma de decir de dónde vino cada código.
     */
    public static function loteImportado(DateTimeInterface $momento): string
    {
        return self::PREFIJO_IMPORTACION . $momento->format('Ymd-Hi');
    }

    /** 26^6 = 308.915.776 códigos posibles. */
    public static function combinaciones(): int
    {
        return 26 ** self::LETRAS;
    }

    /**
     * La URL que codifica el QR: la base configurada más el serial.
     *
     * **El esquema y el host van en mayúsculas a propósito.** Así toda la URL
     * cae dentro de los 45 símbolos del modo alfanumérico de QR, que mete dos
     * caracteres en 11 bits en vez de 8 bits por carácter. Con la URL de
     * EasyPanel eso baja el código de versión 6 (41×41 módulos) a versión 4
     * (33×33): módulos más grandes en la misma placa, que es lo que decide a
     * qué distancia se puede escanear (ver QrMatrix). El serial ya es
     * mayúsculas por construcción.
     *
     * Es seguro porque el esquema y el host son **insensibles a mayúsculas**
     * por RFC 3986 (§3.1 y §3.2.2) y el DNS también. El path, que sí es
     * sensible, se deja intacto: si alguien configurara QR_BASE_URL con un
     * tramo de path, se respeta tal cual y el QR simplemente vuelve a modo
     * byte, sin romperse.
     */
    public static function url(string $base, string $serial): string
    {
        return self::baseEnMayusculas($base) . '/' . $serial;
    }

    /** Pasa a mayúsculas sólo el esquema y el host, nunca el path. */
    private static function baseEnMayusculas(string $base): string
    {
        $separador = strpos($base, '://');
        $desde = $separador === false ? 0 : $separador + 3;

        // Primer '/' después del host: ahí empieza el path, que no se toca.
        $inicioPath = strpos($base, '/', $desde);

        if ($inicioPath === false) {
            return strtoupper($base);
        }

        return strtoupper(substr($base, 0, $inicioPath)) . substr($base, $inicioPath);
    }
}
