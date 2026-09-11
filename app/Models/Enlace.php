<?php

namespace App\Models;

use App\Support\FormatoEnlace;

/**
 * Tabla qr_enlaces: el link público por lote que se le pasa a la grabadora.
 * Ver "Link para la grabadora" en CLAUDE.md.
 */
class Enlace
{
    /**
     * Cota de sorteos ante un choque de token. Cinco alcanzan de sobra aun con
     * los ~34 bits del token corto (ver FormatoEnlace): con un millón de
     * enlaces emitidos, la probabilidad de choque por sorteo es 4,5e-5, así
     * que agotar los cinco es ~1e-22. Existe igual porque sin cota el bucle no
     * terminaría nunca si random_int devolviera siempre lo mismo.
     */
    private const INTENTOS_MAXIMOS = 5;

    /**
     * El token activo del lote, creándolo si todavía no hay uno.
     *
     * Es idempotente a propósito y no se llama crear(): dos generaciones para
     * el mismo cliente dentro del mismo minuto caen en el **mismo lote** (la
     * etiqueta es AAAAMMDD-HHMM), así que la segunda tiene que devolver el
     * enlace que ya existe en vez de fallar o duplicarlo.
     *
     * **No abre transacción propia**: la llama Serial::generar() desde
     * adentro de la suya, y PDO no anida. Llamada suelta (desde
     * SerialController::crearEnlace()), cada INSERT también es atómico por
     * sí mismo.
     *
     * @throws \RuntimeException si se agota la cota de sorteos.
     */
    public static function asegurar(int $clienteId, string $lote): string
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "INSERT INTO qr_enlaces (token, cliente_id, lote)
             VALUES (:token, :cliente_id, :lote)
             ON CONFLICT DO NOTHING
             RETURNING token"
        );

        for ($intento = 0; $intento < self::INTENTOS_MAXIMOS; $intento++) {
            $token = FormatoEnlace::generar();

            $stmt->execute([
                'token'      => $token,
                'cliente_id' => $clienteId,
                'lote'       => $lote,
            ]);

            $insertado = $stmt->fetchColumn();
            if ($insertado !== false) {
                return $insertado;
            }

            // El ON CONFLICT sin target no dice cuál de los dos índices
            // bloqueó: si ya había un enlace activo para este lote, es ese el
            // que corresponde devolver; si no, fue un choque de token y se
            // sortea otro. DO NOTHING es obligatorio (no sólo estilo): una
            // violación de unicidad cruda abortaría la transacción entera de
            // Serial::generar() y se perdería la tanda completa.
            $activo = self::activoDeLote($clienteId, $lote);
            if ($activo !== null) {
                return $activo;
            }
        }

        throw new \RuntimeException('No se pudo generar un token de enlace único');
    }

    public static function activoDeLote(int $clienteId, string $lote): ?string
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT token FROM qr_enlaces
              WHERE cliente_id = :cliente_id AND lote = :lote AND activo"
        );
        $stmt->execute(['cliente_id' => $clienteId, 'lote' => $lote]);
        $token = $stmt->fetchColumn();

        return $token !== false ? $token : null;
    }

    /**
     * Resuelve un token a su lote. Devuelve null si el formato no es el
     * esperado, si el token no existe o si está anulado: los tres casos se
     * contestan con el mismo 404, indistinguible.
     *
     * @return array{cliente_id:int, lote:string}|null
     */
    public static function porToken(string $token): ?array
    {
        if (!FormatoEnlace::esValido($token)) {
            return null;
        }

        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT cliente_id, lote FROM qr_enlaces WHERE token = :token AND activo"
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return ['cliente_id' => (int) $row['cliente_id'], 'lote' => $row['lote']];
    }

    /** Anula el enlace activo de un lote. false si no había ninguno. */
    public static function anular(int $clienteId, string $lote): bool
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "UPDATE qr_enlaces
                SET activo = FALSE, baja_en = now()
              WHERE cliente_id = :cliente_id AND lote = :lote AND activo"
        );
        $stmt->execute(['cliente_id' => $clienteId, 'lote' => $lote]);

        return $stmt->rowCount() > 0;
    }
}
