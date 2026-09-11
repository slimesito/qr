<?php

namespace App\Controllers;

use App\Models\Cliente;
use App\Models\Serial;
use App\Support\FormatoSerial;
use App\Support\QrSvg;

/**
 * Devuelve la imagen SVG de un serial.
 */
class QrController
{
    public function svg(): void
    {
        $serial = FormatoSerial::normalizar((string) ($_GET['serial'] ?? ''));

        if (!FormatoSerial::esValido($serial)) {
            $this->error(400, 'Serial con formato inválido');
            return;
        }

        $config = require BASE_PATH . '/config/app.php';
        $base = $config['qr_base_url'];

        // Sin QR_BASE_URL no se genera nada. Un QR con la URL equivocada es peor
        // que no tener QR: una vez impreso y pegado, no hay forma de corregirlo.
        if ($base === '') {
            $this->error(409, 'Falta configurar QR_BASE_URL: sin eso el QR no puede apuntar a ningún lado');
            return;
        }

        $fila = Serial::buscar($serial);

        if ($fila === null) {
            $this->error(404, 'El serial no existe');
            return;
        }

        $clienteId = $fila['cliente_id'];

        $lado = (int) ($_GET['lado'] ?? 256);
        $lado = max(64, min(2048, $lado));

        header('Content-Type: image/svg+xml; charset=utf-8');
        // El SVG se arma con QR_BASE_URL y el logo del cliente, y los dos
        // pueden cambiar (la mudanza de dominio pendiente, un logo nuevo): no
        // conviene que quede cacheado apuntando a algo viejo. Antes decía
        // "immutable" por un año, que ya era un bug latente y se aprovecha
        // este cambio para corregirlo — mismo criterio que /qr.zip.
        header('Cache-Control: no-store');
        header('Content-Disposition: inline; filename="' . $serial . '.svg"');

        echo QrSvg::render(FormatoSerial::url($base, $serial), $lado, Cliente::logo($clienteId));
    }

    private function error(int $codigo, string $mensaje): void
    {
        http_response_code($codigo);
        header('Content-Type: text/plain; charset=utf-8');
        echo $mensaje;
    }
}
