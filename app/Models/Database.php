<?php

namespace App\Models;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $connection = null;

    /**
     * Conexión PDO a PostgreSQL, singleton estático y lazy.
     *
     * A diferencia del proyecto previo, ante un fallo de conexión NO se hace
     * echo + exit: eso rompe cualquier respuesta que no sea JSON y filtra el
     * mensaje de PDO (host, usuario, base) al cliente. Acá se lanza y cada
     * controller decide cómo responder.
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $config = require BASE_PATH . '/config/database.php';

            $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";

            try {
                self::$connection = new PDO($dsn, $config['username'], $config['password'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                // El detalle va al log; hacia afuera sólo un mensaje genérico.
                error_log('[Database] ' . $e->getMessage());
                throw new RuntimeException('No se pudo conectar a la base de datos', 0, $e);
            }
        }

        return self::$connection;
    }
}
