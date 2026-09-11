<?php

namespace App\Models;

use App\Support\FormatoSerial;
use DateTimeInterface;

/**
 * Tabla qr_seriales.
 */
class Serial
{
    /**
     * Genera $cantidad seriales nuevos para un cliente.
     *
     * Cada código son 6 letras sorteadas al azar. Como no hay ningún orden que
     * garantice que sean nuevos, la unicidad la impone el índice UNIQUE de la
     * tabla vía ON CONFLICT DO NOTHING: si el código sorteado ya existía, el
     * INSERT no hace nada, se cuenta como salteado y se sortea otro. Así se
     * cumple la regla de que un QR repetido se saltea, y el pedido igual
     * entrega los $cantidad pedidos.
     *
     * @return array{lote:string, creados:int, salteados:int, seriales:array<int,string>, tamano_mm:float, enlace_token:?string}
     */
    public static function generar(int $clienteId, int $cantidad, float $tamanoMm, DateTimeInterface $momento): array
    {
        $db = Database::getConnection();
        $lote = FormatoSerial::lote($momento);

        $stmt = $db->prepare(
            "INSERT INTO qr_seriales (serial, cliente_id, lote, tamano_mm)
             VALUES (:serial, :cliente_id, :lote, :tamano_mm)
             ON CONFLICT (serial) DO NOTHING"
        );

        $creados = [];
        $salteados = 0;

        // Cota de seguridad. Con 308 millones de combinaciones las colisiones
        // son rarísimas, pero si la tabla creciera hasta llenar el espacio, sin
        // este tope el bucle no terminaría nunca y colgaría el request.
        $intentosMaximos = $cantidad * 20 + 100;
        $intentos = 0;

        $db->beginTransaction();
        try {
            while (count($creados) < $cantidad && $intentos < $intentosMaximos) {
                $intentos++;
                $serial = FormatoSerial::generar();

                $stmt->execute([
                    'serial'     => $serial,
                    'cliente_id' => $clienteId,
                    'lote'       => $lote,
                    'tamano_mm'  => $tamanoMm,
                ]);

                if ($stmt->rowCount() > 0) {
                    $creados[] = $serial;
                } else {
                    $salteados++;
                }
            }

            // El lote nace con su enlace público de una: es lo que evita una
            // ventana donde el lote existe pero todavía no tiene link (ver
            // "Link para la grabadora" en CLAUDE.md). Sin códigos creados no
            // tiene sentido: el controller ya responde 409 en ese caso, y un
            // enlace a un lote vacío sería basura activa en la tabla.
            $token = $creados !== [] ? Enlace::asegurar($clienteId, $lote) : null;

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return [
            'lote'         => $lote,
            'creados'      => count($creados),
            'salteados'    => $salteados,
            'seriales'     => $creados,
            'tamano_mm'    => $tamanoMm,
            'enlace_token' => $token,
        ];
    }

    /**
     * Da de alta códigos que ya existían afuera, en vez de sortear nuevos.
     *
     * Se puede llamar varias veces para el mismo cliente: cada llamada arma su
     * propia tanda con etiqueta `IMP-AAAAMMDD-HHMM` (ver
     * FormatoSerial::loteImportado()), así que dos importaciones separadas no
     * se confunden entre sí.
     *
     * Los seriales llegan ya normalizados y validados: acá sólo se insertan.
     * Se usa el mismo ON CONFLICT DO NOTHING que la generación, así que un
     * código repetido -ya sea porque está dos veces en lo que se pegó, porque
     * ya estaba cargado en esta tanda, o porque ya se había importado antes-
     * no rompe nada: se cuenta como duplicado y se sigue.
     *
     * @param  array<int, string> $seriales
     * @return array{lote:string, importados:int, duplicados:int}
     */
    public static function importar(int $clienteId, array $seriales, DateTimeInterface $momento): array
    {
        $db = Database::getConnection();
        $lote = FormatoSerial::loteImportado($momento);

        $stmt = $db->prepare(
            "INSERT INTO qr_seriales (serial, cliente_id, lote, origen)
             VALUES (:serial, :cliente_id, :lote, 'importado')
             ON CONFLICT (serial) DO NOTHING"
        );

        $importados = 0;
        $duplicados = 0;

        $db->beginTransaction();
        try {
            foreach ($seriales as $serial) {
                $stmt->execute([
                    'serial'     => $serial,
                    'cliente_id' => $clienteId,
                    'lote'       => $lote,
                ]);

                if ($stmt->rowCount() > 0) {
                    $importados++;
                } else {
                    $duplicados++;
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return [
            'lote'        => $lote,
            'importados'  => $importados,
            'duplicados'  => $duplicados,
        ];
    }

    /**
     * Sólo los códigos **generados**: los importados no producen imagen (son
     * un inventario de reserva, no una tanda para imprimir), así que quedan
     * afuera de esta consulta, que es la que alimenta la hoja de QR y el zip
     * cuando no se pide un lote puntual.
     *
     * @return array<int, array{id:int, serial:string, lote:?string, origen:string, tamano_mm:?string, creado_en:string}>
     */
    public static function porCliente(int $clienteId): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT id, serial, lote, origen, tamano_mm, creado_en
               FROM qr_seriales
              WHERE cliente_id = :cliente_id AND origen = 'generado'
              ORDER BY creado_en DESC, serial"
        );
        $stmt->execute(['cliente_id' => $clienteId]);

        return $stmt->fetchAll();
    }

    /**
     * Igual que porCliente() pero acotado a una tanda, para imprimir sólo lo
     * último generado en vez de todo el histórico del cliente. El filtro por
     * origen es el mismo: un lote es, por definición, una tanda generada.
     *
     * @return array<int, array{id:int, serial:string, lote:?string, origen:string, tamano_mm:?string, creado_en:string}>
     */
    public static function porLote(int $clienteId, string $lote): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT id, serial, lote, origen, tamano_mm, creado_en
               FROM qr_seriales
              WHERE cliente_id = :cliente_id AND lote = :lote AND origen = 'generado'
              ORDER BY creado_en DESC, serial"
        );
        $stmt->execute(['cliente_id' => $clienteId, 'lote' => $lote]);

        return $stmt->fetchAll();
    }

    /**
     * Tandas **generadas** de un cliente, de la más nueva a la más vieja, con
     * cuántos seriales tiene cada una. Alimenta la tabla de lotes del panel.
     * Los códigos importados no aparecen acá: viven detrás del botón "QRs
     * viejos" (ver importadosDeCliente()), porque son un inventario que se
     * carga una sola vez y no una tanda para imprimir.
     *
     * Además del timestamp con zona horaria se devuelve `creado_ts`, el mismo
     * instante en segundos Unix. Es lo que consume el navegador para mostrar la
     * fecha en la zona horaria de quien mira: un epoch no tiene ambigüedad de
     * formato ni de zona, mientras que el texto que emite PostgreSQL
     * ("2026-09-07 21:54:24.5+00") no lo parsean todos los navegadores igual.
     *
     * @return array<int, array{lote:string, cantidad:int, tamano_mm:?float, creado_en:string, creado_ts:int, enlace_token:?string}>
     */
    public static function lotesDeCliente(int $clienteId): array
    {
        $db = Database::getConnection();

        // El LEFT JOIN trae el token del enlace activo de cada lote, si lo
        // tiene. e.token entra en el GROUP BY -Postgres lo exige- y eso es
        // seguro sólo porque qr_enlaces_lote_activo_idx garantiza a lo sumo
        // una fila del lado derecho: si ese índice cambiara, este COUNT(*)
        // empezaría a inflarse.
        $stmt = $db->prepare(
            "SELECT s.lote,
                    COUNT(*) AS cantidad,
                    MIN(s.tamano_mm) AS tamano_mm,
                    MIN(s.creado_en) AS creado_en,
                    EXTRACT(EPOCH FROM MIN(s.creado_en))::bigint AS creado_ts,
                    e.token AS enlace_token
               FROM qr_seriales s
               LEFT JOIN qr_enlaces e
                      ON e.cliente_id = s.cliente_id AND e.lote = s.lote AND e.activo
              WHERE s.cliente_id = :cliente_id AND s.lote IS NOT NULL AND s.origen = 'generado'
              GROUP BY s.lote, e.token
              ORDER BY MIN(s.creado_en) DESC"
        );
        $stmt->execute(['cliente_id' => $clienteId]);

        // MIN(tamano_mm) alcanza porque un lote entero se genera con una sola
        // llamada a Serial::generar(), así que todas sus filas comparten
        // medida; NULL sólo puede darse en lotes anteriores a esta columna.
        return array_map(static function (array $row): array {
            return [
                'lote'         => $row['lote'],
                'cantidad'     => (int) $row['cantidad'],
                'tamano_mm'    => $row['tamano_mm'] !== null ? (float) $row['tamano_mm'] : null,
                'creado_en'    => $row['creado_en'],
                'creado_ts'    => (int) $row['creado_ts'],
                'enlace_token' => $row['enlace_token'],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Los códigos importados de un cliente, para el bloc de sólo lectura del
     * modal "QRs viejos" (no producen imagen: no hay hoja ni zip para ellos).
     * No se filtra por lote a propósito: si un cliente quedara con más de una
     * importación (etiquetas `IMP-` distintas), las junta a todas. Viene
     * ordenado de más nuevo a más viejo, así que el primer elemento es
     * siempre el último importado.
     *
     * @return array<int, array{id:int, serial:string, lote:?string, origen:string, creado_en:string, creado_ts:int}>
     */
    public static function importadosDeCliente(int $clienteId): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT id, serial, lote, origen, creado_en,
                    EXTRACT(EPOCH FROM creado_en)::bigint AS creado_ts
               FROM qr_seriales
              WHERE cliente_id = :cliente_id AND origen = 'importado'
              ORDER BY creado_en DESC, serial"
        );
        $stmt->execute(['cliente_id' => $clienteId]);

        return array_map(static function (array $row): array {
            $row['creado_ts'] = (int) $row['creado_ts'];
            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * La fila de un serial, o null si no existe. QrController la usa para
     * /qr.svg y SerialController para la descarga individual /qr-unidad.svg.
     *
     * @return array{id:int, serial:string, cliente_id:int, lote:?string, origen:string, tamano_mm:?string}|null
     */
    public static function buscar(string $serial): ?array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT id, serial, cliente_id, lote, origen, tamano_mm
               FROM qr_seriales
              WHERE serial = :serial"
        );
        $stmt->execute(['serial' => $serial]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['cliente_id'] = (int) $row['cliente_id'];

        return $row;
    }

    /**
     * Igual que buscar(), pero exigiendo que el serial pertenezca a ese lote
     * de ese cliente y sea generado. Es la validación de la descarga
     * individual pública (/grabado-unidad.svg): no alcanza con que el código
     * exista, tiene que ser de este lote -si no, un token válido serviría
     * para bajar cualquier código de cualquier cliente cambiando el ?serial=.
     *
     * @return array{id:int, serial:string, cliente_id:int, lote:?string, origen:string, tamano_mm:?string}|null
     */
    public static function buscarEnLote(int $clienteId, string $lote, string $serial): ?array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT id, serial, cliente_id, lote, origen, tamano_mm
               FROM qr_seriales
              WHERE serial = :serial AND cliente_id = :cliente_id AND lote = :lote
                    AND origen = 'generado'"
        );
        $stmt->execute(['serial' => $serial, 'cliente_id' => $clienteId, 'lote' => $lote]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['cliente_id'] = (int) $row['cliente_id'];

        return $row;
    }

    /** Si el lote tiene al menos un código generado. Guarda de crear_enlace: no se acuñan tokens para lotes inventados. */
    public static function existeLote(int $clienteId, string $lote): bool
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT 1 FROM qr_seriales
              WHERE cliente_id = :cliente_id AND lote = :lote AND origen = 'generado'
              LIMIT 1"
        );
        $stmt->execute(['cliente_id' => $clienteId, 'lote' => $lote]);

        return (bool) $stmt->fetchColumn();
    }
}
