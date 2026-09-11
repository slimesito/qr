<?php

namespace App\Controllers;

use App\Models\Cliente;
use App\Support\QrLogo;
use App\Support\Respuesta;
use App\Support\SaneadorSvg;

/**
 * Alta y baja de clientes. No hay edición: el CRUD es sólo alta y baja.
 */
class ClienteController
{
    public function index(bool $autoNuevo = false): void
    {
        $config = require BASE_PATH . '/config/app.php';

        $contenido = $this->render('Clientes/index', [
            'autoNuevo'  => $autoNuevo,
            'qrBaseUrl'  => $config['qr_base_url'],
        ]);

        echo $this->render('Layouts/app', [
            'titulo'      => 'Clientes',
            'contenido'   => $contenido,
            'scripts'     => ['/js/clientes.js', '/js/seriales.js'],
            'autenticado' => true,
            // El sidebar de clientes es propio de esta pantalla: el login
            // comparte el mismo layout y no lo pide, así que el envoltorio se
            // ensancha sólo acá.
            'sidebar'     => true,
        ]);
    }

    public function nuevo(): void
    {
        $this->index(true);
    }

    public function api(): void
    {
        $accion = $_GET['action'] ?? '';

        try {
            switch ($accion) {
                case 'list':
                    Respuesta::datos(Cliente::all(!empty($_GET['inactivos'])));
                    return;

                case 'get':
                    $cliente = Cliente::find((int) ($_GET['id'] ?? 0));
                    if (!$cliente) {
                        Respuesta::error('El cliente no existe', 404);
                        return;
                    }
                    Respuesta::datos($cliente);
                    return;

                case 'create':
                    $this->crear();
                    return;

                case 'delete':
                    $this->darDeBaja();
                    return;

                case 'logo':
                    Respuesta::esPost() ? $this->guardarLogo() : $this->verLogo();
                    return;

                default:
                    Respuesta::error('Acción no válida', 400);
                    return;
            }
        } catch (\InvalidArgumentException $e) {
            // Mensajes pensados para mostrarle al usuario (sobre todo los del
            // saneador de SVG: "convertí el texto a curvas", etc.) — si esto
            // cayera en el catch de abajo, el \Throwable genérico los taparía
            // con "Error interno al procesar la solicitud".
            Respuesta::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            // El mensaje real va al log: puede traer el SQL o datos de conexión.
            error_log('[ClienteController] ' . $e->getMessage());
            Respuesta::error('Error interno al procesar la solicitud', 500);
        }
    }

    private function crear(): void
    {
        if (!Respuesta::esPost()) {
            Respuesta::error('Se esperaba POST', 405);
            return;
        }

        $cuerpo = Respuesta::cuerpo();
        $nombre = trim((string) ($cuerpo['nombre'] ?? ''));

        if ($nombre === '') {
            Respuesta::error('El nombre es obligatorio', 400);
            return;
        }

        if (mb_strlen($nombre) > 120) {
            Respuesta::error('El nombre no puede superar los 120 caracteres', 400);
            return;
        }

        // El id es opcional: en blanco lo asigna la base, como hasta ahora.
        $idPedido = trim((string) ($cuerpo['id'] ?? ''));
        $id       = null;

        if ($idPedido !== '') {
            // El tope es el de un INTEGER de PostgreSQL: más arriba, el INSERT
            // fallaría con un error de la base en vez de un mensaje entendible.
            if (!ctype_digit($idPedido) || (int) $idPedido < 1 || (int) $idPedido > 2147483647) {
                Respuesta::error('El ID debe ser un número entero entre 1 y 2147483647', 400);
                return;
            }

            $id = (int) $idPedido;
        }

        // El logo es opcional al alta: sin él, el cliente usa el logo de
        // prueba (QrLogo::porDefecto()) hasta que se le cargue uno propio
        // desde la tabla. Si se manda uno, se sanea igual que siempre — un
        // SVG rechazado (InvalidArgumentException) sigue subiendo hasta el
        // catch de api() sin que se haya creado nada.
        $svgCrudo = trim((string) ($cuerpo['logo'] ?? ''));
        $logo = $svgCrudo !== '' ? SaneadorSvg::sanear($svgCrudo) : null;

        $creado = Cliente::create($nombre, $logo, $id);

        if ($creado === null) {
            Respuesta::error('Ya existe un cliente con ese ID', 409);
            return;
        }

        Respuesta::ok(['id' => $creado], 201);
    }

    private function darDeBaja(): void
    {
        if (!Respuesta::esPost()) {
            Respuesta::error('Se esperaba POST', 405);
            return;
        }

        $id = (int) ($_GET['id'] ?? 0);

        // Baja lógica: los seriales del cliente no se tocan ni se liberan,
        // porque esos QR ya pueden estar impresos y pegados.
        if (!Cliente::darDeBaja($id)) {
            Respuesta::error('El cliente no existe o ya estaba dado de baja', 404);
            return;
        }

        Respuesta::ok();
    }

    /**
     * Devuelve el logo **efectivo** del cliente: el propio si lo tiene, o si
     * no el mismo logo de prueba que usan sus QR (Cliente::logo() ya resuelve
     * esa caída). Lo consume el modal "Agregar/Cambiar logo" para mostrar
     * siempre lo que hoy se está dibujando, sea cual sea el origen.
     */
    private function verLogo(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $cliente = Cliente::find($id);

        if (!$cliente) {
            Respuesta::error('El cliente no existe', 404);
            return;
        }

        $logo = Cliente::logo($id);

        Respuesta::datos([
            'tiene_logo' => $cliente['tiene_logo'],
            'viewbox'    => $logo->viewBoxString(),
            'markup'     => $logo->markup,
        ]);
    }

    /**
     * Reemplaza el logo de un cliente. No hay acción para quitarlo (se pidió
     * así): sólo se puede reemplazar uno por otro.
     */
    private function guardarLogo(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $cuerpo = Respuesta::cuerpo();
        $svgCrudo = (string) ($cuerpo['svg'] ?? '');

        if (trim($svgCrudo) === '') {
            Respuesta::error('No se subió ningún archivo', 400);
            return;
        }

        $logo = SaneadorSvg::sanear($svgCrudo);

        if (!Cliente::guardarLogo($id, $logo)) {
            Respuesta::error('El cliente no existe o está dado de baja', 404);
            return;
        }

        // Se devuelve el resultado ya saneado (no lo que se subió) para que
        // el modal muestre exactamente lo que quedó guardado.
        Respuesta::datos(['viewbox' => $logo->viewBoxString(), 'markup' => $logo->markup]);
    }

    private function render(string $vista, array $datos = []): string
    {
        extract($datos);
        ob_start();
        require BASE_PATH . '/app/Views/' . $vista . '.php';

        return ob_get_clean();
    }
}
