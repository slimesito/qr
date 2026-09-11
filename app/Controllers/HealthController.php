<?php

namespace App\Controllers;

use PDO;

/**
 * Endpoint público de healthcheck. Lo consume el HEALTHCHECK del Dockerfile.
 * Verifica que la app responda y que PostgreSQL esté accesible.
 */
class HealthController
{
    private const SERVICE = 'neo-qr';

    public function check(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        try {
            // Conexión propia en vez de Database::getConnection(): el health
            // tiene que poder reportar "la base no responde" con su propio
            // código, no heredar el manejo de errores del modelo.
            $config = require BASE_PATH . '/config/database.php';

            // connect_timeout es imprescindible: sin él, si la base no está,
            // el healthcheck queda colgado hasta el timeout del sistema y
            // Docker lo mata antes de recibir respuesta.
            $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']};connect_timeout=3";

            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->query('SELECT 1');

            $this->responder(true, 'healthy', 'ok', 200);
        } catch (\Throwable $e) {
            // El detalle queda en el log; no se expone, que es un endpoint público.
            error_log('[health] ' . $e->getMessage());
            $this->responder(false, 'error', 'database unreachable', 503);
        }
    }

    private function responder(bool $ok, string $status, string $mensaje, int $codigo): void
    {
        http_response_code($codigo);

        echo json_encode([
            'ok'      => $ok,
            'status'  => $status,
            'service' => self::SERVICE,
            'ts'      => time(),
            'message' => $mensaje,
        ]);
    }
}
