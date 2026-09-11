<?php

namespace App\Models;

use App\Support\QrLogo;

/**
 * Tabla qr_clientes.
 *
 * La identidad del cliente (el nombre) sigue siendo alta y baja únicamente,
 * tal como se pidió: no hay edición. El logo es la única excepción a esa
 * regla — ver guardarLogo() — porque es arte reemplazable, y obligar a
 * recrear el cliente para corregirlo dejaría huérfanos sus seriales.
 * La baja es lógica (activo = false); ver darDeBaja() para el motivo.
 */
class Cliente
{
    /**
     * Listado con la cantidad de seriales de cada cliente, generados e
     * importados por igual: es el total de códigos reservados, sin importar de
     * dónde salieron.
     *
     * El conteo sale de un LEFT JOIN, no de una query por fila: el proyecto previo
     * arma su listado con 3 queries extra por cliente y eso es un N+1 que acá
     * no se copia.
     *
     * tiene_logo sale de un IS NOT NULL, no del logo en sí: la markup del logo
     * (hasta 6 KB) no tiene por qué viajar en un listado de muchos clientes.
     * Para el arte completo está logo(), aparte.
     *
     * @return array<int, array{id:int, nombre:string, activo:bool, creado_en:string, baja_en:?string, seriales:int, tiene_logo:bool}>
     */
    public static function all(bool $incluirInactivos = false): array
    {
        $db = Database::getConnection();

        $where = $incluirInactivos ? '' : 'WHERE c.activo = TRUE';

        $sql = "SELECT c.id, c.nombre, c.activo, c.creado_en, c.baja_en,
                       (c.logo_svg IS NOT NULL) AS tiene_logo,
                       COUNT(s.id) AS seriales
                  FROM qr_clientes c
                  LEFT JOIN qr_seriales s ON s.cliente_id = c.id
                  {$where}
                 GROUP BY c.id
                 ORDER BY c.id DESC";

        return array_map([self::class, 'formatear'], $db->query($sql)->fetchAll());
    }

    public static function find(int $id): ?array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT c.id, c.nombre, c.activo, c.creado_en, c.baja_en,
                    (c.logo_svg IS NOT NULL) AS tiene_logo,
                    COUNT(s.id) AS seriales
               FROM qr_clientes c
               LEFT JOIN qr_seriales s ON s.cliente_id = c.id
              WHERE c.id = :id
              GROUP BY c.id"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? self::formatear($row) : null;
    }

    /**
     * El logo del cliente, listo para QrSvg. Se pide con una query aparte de
     * find()/all() a propósito: así ni el listado ni el chequeo de existencia
     * que hacen generar()/importar() en cada apertura del panel arrastran el
     * markup guardado. Si la fila no tiene logo, o si lo que hay guardado no
     * parsea (una fila corrupta), cae al placeholder — el render nunca debe
     * romperse por esto.
     */
    public static function logo(int $id): QrLogo
    {
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT logo_svg, logo_viewbox FROM qr_clientes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row || $row['logo_svg'] === null || $row['logo_viewbox'] === null) {
            return QrLogo::porDefecto();
        }

        try {
            return QrLogo::desdeBase($row['logo_viewbox'], $row['logo_svg']);
        } catch (\InvalidArgumentException $e) {
            error_log('[Cliente] logo inválido para el cliente ' . $id . ': ' . $e->getMessage());

            return QrLogo::porDefecto();
        }
    }

    /**
     * Da de alta un cliente y devuelve su id.
     *
     * Con $id en null lo asigna la base (el caso normal). Con un id explícito se
     * respeta el pedido, que sirve para reponer un cliente con el mismo número
     * que ya tenía en otro lado; si ese id está ocupado devuelve null y no se
     * inserta nada.
     *
     * El logo es opcional al alta: sin uno, el cliente queda con
     * logo_svg/logo_viewbox en NULL y sus QR usan QrLogo::porDefecto() hasta
     * que se le cargue uno propio desde la tabla. Cuando se manda, se inserta
     * en la misma fila: no hay una transacción "crear cliente, después
     * guardar logo" que pueda dejar a medio camino un cliente con un logo a
     * medio guardar si algo falla entre las dos.
     */
    public static function create(string $nombre, ?QrLogo $logo = null, ?int $id = null): ?int
    {
        $db = Database::getConnection();

        if ($id === null) {
            $stmt = $db->prepare(
                "INSERT INTO qr_clientes (nombre, logo_svg, logo_viewbox)
                 VALUES (:nombre, :logo_svg, :logo_viewbox) RETURNING id"
            );
            $stmt->execute([
                'nombre'       => $nombre,
                'logo_svg'     => $logo?->markup,
                'logo_viewbox' => $logo?->viewBoxString(),
            ]);

            return (int) $stmt->fetchColumn();
        }

        // La columna es GENERATED ALWAYS, así que escribirla a mano exige
        // OVERRIDING SYSTEM VALUE. El id ocupado lo detecta el PRIMARY KEY con
        // ON CONFLICT, no un SELECT previo: mismo criterio que el UNIQUE de
        // serial, sin ventana de carrera entre el chequeo y el INSERT.
        $db->beginTransaction();

        try {
            $stmt = $db->prepare(
                "INSERT INTO qr_clientes (id, nombre, logo_svg, logo_viewbox)
                 OVERRIDING SYSTEM VALUE
                 VALUES (:id, :nombre, :logo_svg, :logo_viewbox)
                 ON CONFLICT (id) DO NOTHING
                 RETURNING id"
            );
            $stmt->execute([
                'id'           => $id,
                'nombre'       => $nombre,
                'logo_svg'     => $logo?->markup,
                'logo_viewbox' => $logo?->viewBoxString(),
            ]);
            $creado = $stmt->fetchColumn();

            if ($creado === false) {
                $db->rollBack();

                return null;
            }

            // Insertar a mano no mueve la secuencia de la identidad, así que un
            // alta automática posterior volvería a intentar un id ya usado y
            // fallaría. Se la reacomoda al máximo actual: el próximo automático
            // es MAX+1, que por definición está libre.
            $db->exec(
                "SELECT setval(pg_get_serial_sequence('qr_clientes', 'id'),
                               (SELECT MAX(id) FROM qr_clientes))"
            );

            $db->commit();

            return (int) $creado;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Baja lógica. Los seriales del cliente quedan intactos y no se liberan:
     * esos QR ya pueden estar impresos y pegados, y reciclar los códigos haría
     * que una etiqueta física terminara apuntando al destino equivocado.
     *
     * Devuelve false si el cliente no existe o ya estaba dado de baja.
     */
    public static function darDeBaja(int $id): bool
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "UPDATE qr_clientes
                SET activo = FALSE, baja_en = now()
              WHERE id = :id AND activo = TRUE"
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public static function existeActivo(int $id): bool
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT 1 FROM qr_clientes WHERE id = :id AND activo = TRUE");
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Reemplaza el logo de un cliente activo. No hay forma de quitarlo (se
     * pidió así): sólo reemplazar uno por otro. Devuelve false si el cliente
     * no existe o está dado de baja.
     */
    public static function guardarLogo(int $id, QrLogo $logo): bool
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "UPDATE qr_clientes
                SET logo_svg = :logo_svg, logo_viewbox = :logo_viewbox
              WHERE id = :id AND activo = TRUE"
        );
        $stmt->execute([
            'id'           => $id,
            'logo_svg'     => $logo->markup,
            'logo_viewbox' => $logo->viewBoxString(),
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * PostgreSQL devuelve los COUNT como string y los boolean como bool nativo
     * sólo con EMULATE_PREPARES en false; se normaliza igual para que la vista
     * y el JSON reciban siempre los mismos tipos.
     */
    private static function formatear(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'nombre'     => $row['nombre'],
            'activo'     => (bool) $row['activo'],
            'creado_en'  => $row['creado_en'],
            'baja_en'    => $row['baja_en'],
            'seriales'   => (int) $row['seriales'],
            'tiene_logo' => (bool) $row['tiene_logo'],
        ];
    }
}
