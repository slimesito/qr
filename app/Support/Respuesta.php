<?php

namespace App\Support;

/**
 * Respuestas JSON de la API.
 *
 * Envelope canónico, igual que en el proyecto previo: {"ok": true, ...} o
 * {"ok": false, "error": "..."}. La diferencia es que acá los errores viajan
 * con su código HTTP real, no con un 200 y un ok:false adentro.
 */
class Respuesta
{
    public static function ok(array $extra = [], int $codigo = 200): void
    {
        self::emitir(['ok' => true] + $extra, $codigo);
    }

    public static function datos($data, int $codigo = 200): void
    {
        self::emitir(['ok' => true, 'data' => $data], $codigo);
    }

    public static function error(string $mensaje, int $codigo = 400): void
    {
        self::emitir(['ok' => false, 'error' => $mensaje], $codigo);
    }

    /**
     * Cuerpo JSON de un request. La API se consume con fetch desde el propio
     * panel, así que los datos llegan por el body y no por $_POST.
     */
    public static function cuerpo(): array
    {
        $crudo = file_get_contents('php://input');
        if ($crudo === false || $crudo === '') {
            return [];
        }

        $datos = json_decode($crudo, true);

        return is_array($datos) ? $datos : [];
    }

    public static function esPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    private static function emitir(array $payload, int $codigo): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
