<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Armador de archivos ZIP, escrito a mano.
 *
 * La imagen de producción (php:8.2-apache) no trae la extensión zip: el
 * Dockerfile sólo compila pdo_pgsql, y sumar libzip para empaquetar un puñado
 * de SVG de texto no se justifica. Es el mismo criterio con el que el encoder
 * de QR es propio en vez de una librería.
 *
 * Guarda **sin comprimir** (método 0, "stored"). Con eso alcanza y sobra: los
 * SVG son chicos y el tope por hoja son 500, así que el .zip queda en pocos
 * megas y no hace falta ni zlib ni ZIP64.
 */
class Zip
{
    /**
     * @param array<string, string> $archivos nombre dentro del zip => contenido
     */
    public static function armar(array $archivos, DateTimeInterface $momento): string
    {
        // El ZIP guarda la fecha en el formato empaquetado del MS-DOS de los 80:
        // 16 bits para la hora (con los segundos en pasos de dos) y 16 para la
        // fecha, contada desde 1980.
        $hora = ((int) $momento->format('H') << 11)
            | ((int) $momento->format('i') << 5)
            | ((int) $momento->format('s') >> 1);
        $fecha = (((int) $momento->format('Y') - 1980) << 9)
            | ((int) $momento->format('n') << 5)
            | (int) $momento->format('j');

        $locales = '';
        $central = '';
        $offset = 0;

        foreach ($archivos as $nombre => $contenido) {
            $nombre = (string) $nombre;
            $largo = strlen($contenido);
            $crc = crc32($contenido);

            $local = pack('V', 0x04034b50)      // firma de encabezado local
                . pack('v', 10)                 // versión mínima para extraer
                . pack('v', 0)                  // flags
                . pack('v', 0)                  // método: 0 = sin comprimir
                . pack('v', $hora)
                . pack('v', $fecha)
                . pack('V', $crc)
                . pack('V', $largo)             // tamaño comprimido
                . pack('V', $largo)             // tamaño original (igual: no se comprime)
                . pack('v', strlen($nombre))
                . pack('v', 0)                  // sin campo extra
                . $nombre;

            $locales .= $local . $contenido;

            // La entrada del directorio central repite los datos y agrega dónde
            // empieza su encabezado local: es el índice por el que el que abre
            // el zip encuentra cada archivo sin recorrerlo entero.
            $central .= pack('V', 0x02014b50)
                . pack('v', 20)                 // versión con la que se creó
                . pack('v', 10)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', $hora)
                . pack('v', $fecha)
                . pack('V', $crc)
                . pack('V', $largo)
                . pack('V', $largo)
                . pack('v', strlen($nombre))
                . pack('v', 0)                  // extra
                . pack('v', 0)                  // comentario
                . pack('v', 0)                  // disco
                . pack('v', 0)                  // atributos internos
                . pack('V', 0)                  // atributos externos
                . pack('V', $offset)
                . $nombre;

            $offset += strlen($local) + $largo;
        }

        $cantidad = count($archivos);

        $fin = pack('V', 0x06054b50)
            . pack('v', 0)                      // número de disco
            . pack('v', 0)                      // disco donde arranca el central
            . pack('v', $cantidad)
            . pack('v', $cantidad)
            . pack('V', strlen($central))
            . pack('V', $offset)                // dónde arranca el directorio central
            . pack('v', 0);                     // sin comentario

        return $locales . $central . $fin;
    }
}
