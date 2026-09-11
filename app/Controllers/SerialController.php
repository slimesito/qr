<?php

namespace App\Controllers;

use App\Models\Cliente;
use App\Models\Enlace;
use App\Models\Serial;
use App\Support\FormatoEnlace;
use App\Support\FormatoSerial;
use App\Support\QrSvg;
use App\Support\Respuesta;
use App\Support\Zip;
use DateTimeImmutable;

/**
 * Generación de seriales y hoja de QR imprimible.
 */
class SerialController
{
    /** Tope por pedido. Acota el bucle de generación y el tamaño del request. */
    private const MAX_POR_TANDA = 5000;

    /** Tope de códigos por hoja: cada QR es un SVG embebido y pesa. */
    private const MAX_POR_HOJA = 500;

    /**
     * Fallback para seriales generados antes de que existiera la columna
     * tamano_mm: es el valor que se venía emitiendo siempre, así que un lote
     * viejo sigue bajando exactamente igual que antes de este cambio.
     */
    private const MM_POR_DEFECTO = 25.0;

    /** Rango aceptado para la medida elegida al generar un lote. */
    private const MM_MINIMO = 5.0;
    private const MM_MAXIMO = 200.0;

    /**
     * Hoja de grabado: A4 vertical, en milímetros de verdad.
     *
     * El margen es de 5 mm y no de los 10 habituales de impresión a propósito:
     * con 10 no entraría a lo ancho un lote de 200 mm, que es el máximo que
     * acepta el formulario, y esa placa quedaría desbordando la hoja.
     */
    private const HOJA_ANCHO_MM = 210.0;
    private const HOJA_ALTO_MM = 297.0;
    private const HOJA_MARGEN_MM = 5.0;

    /** Aire entre placas vecinas. Cada QR ya trae adentro su zona de silencio. */
    private const HOJA_SEPARACION_MM = 5.0;

    /**
     * Holgura al comparar milímetros. Sin esto una placa de 200 mm podría no
     * entrar en un área útil de 200 mm por el error de la suma en punto
     * flotante, y saltaría de fila -o de página- sin motivo.
     */
    private const HOJA_EPSILON = 0.001;

    public function api(): void
    {
        $accion = $_GET['action'] ?? '';

        try {
            switch ($accion) {
                case 'list':
                    $this->listar();
                    return;

                case 'generar':
                    $this->generar();
                    return;

                case 'importar':
                    $this->importar();
                    return;

                case 'importados':
                    $this->importados();
                    return;

                case 'crear_enlace':
                    $this->crearEnlace();
                    return;

                case 'anular_enlace':
                    $this->anularEnlace();
                    return;

                default:
                    Respuesta::error('Acción no válida', 400);
                    return;
            }
        } catch (\Throwable $e) {
            error_log('[SerialController] ' . $e->getMessage());
            Respuesta::error('Error interno al procesar la solicitud', 500);
        }
    }

    private function listar(): void
    {
        $clienteId = (int) ($_GET['cliente_id'] ?? 0);

        if (!Cliente::find($clienteId)) {
            Respuesta::error('El cliente no existe', 404);
            return;
        }

        // Sólo los lotes generados: el panel lista tandas para imprimir, no
        // códigos sueltos. Devolver los seriales uno por uno significaría
        // mandarle miles de filas al navegador para no mostrarlas. Los códigos
        // de un lote se materializan recién en la hoja de QR, que los arma en
        // el servidor.
        $lotes = Serial::lotesDeCliente($clienteId);

        // El link se arma acá y no en el JS: así hay un solo lugar que
        // concatena base y token, y el navegador no tiene que conocer
        // QR_BASE_URL. Va como enlace_url al lado de enlace_token, que el
        // panel sigue necesitando para saber si el lote tiene enlace activo.
        $base = $this->baseDeEnlaces();
        foreach ($lotes as &$lote) {
            $lote['enlace_url'] = $lote['enlace_token'] !== null
                ? FormatoEnlace::url($base, $lote['enlace_token'])
                : null;
        }
        unset($lote);

        Respuesta::datos(['lotes' => $lotes]);
    }

    /**
     * La base de los links para la grabadora: la misma QR_BASE_URL que se
     * codifica en los QR, porque el link y el código impreso viven en el mismo
     * dominio -uno es /TOKEN y el otro /SERIAL, en la misma raíz.
     */
    private function baseDeEnlaces(): string
    {
        $config = require BASE_PATH . '/config/app.php';

        return $config['qr_base_url'];
    }

    /**
     * Los códigos importados de un cliente, uno por uno. Se piden aparte de
     * listar() -y sólo cuando se abre el modal "QRs viejos"- para no mandar
     * hasta 5000 seriales cada vez que se abre el panel de un cliente.
     */
    private function importados(): void
    {
        $clienteId = (int) ($_GET['cliente_id'] ?? 0);

        if (!Cliente::find($clienteId)) {
            Respuesta::error('El cliente no existe', 404);
            return;
        }

        Respuesta::datos(['seriales' => Serial::importadosDeCliente($clienteId)]);
    }

    /**
     * Crea (o reutiliza) el enlace público de un lote. Es idempotente porque
     * Enlace::asegurar() lo es: pedirlo dos veces para el mismo lote devuelve
     * el mismo token en vez de fallar.
     */
    private function crearEnlace(): void
    {
        if (!Respuesta::esPost()) {
            Respuesta::error('Se esperaba POST', 405);
            return;
        }

        $cuerpo = Respuesta::cuerpo();
        $clienteId = (int) ($cuerpo['cliente_id'] ?? 0);
        $lote = (string) ($cuerpo['lote'] ?? '');

        // Mismo criterio que generar()/importar(): no se emiten credenciales
        // nuevas para un cliente dado de baja.
        if (!Cliente::existeActivo($clienteId)) {
            Respuesta::error('El cliente no existe o está dado de baja', 404);
            return;
        }

        // Sin esta guarda se podrían acuñar tokens para lotes inventados
        // desde la consola del navegador, y quedarían como basura activa en
        // la tabla de enlaces.
        if (!Serial::existeLote($clienteId, $lote)) {
            Respuesta::error('El lote no existe', 404);
            return;
        }

        $token = Enlace::asegurar($clienteId, $lote);

        Respuesta::datos([
            'token' => $token,
            'url'   => FormatoEnlace::url($this->baseDeEnlaces(), $token),
        ]);
    }

    /**
     * Anula el enlace activo de un lote. A diferencia de crearEnlace(), acá
     * se usa Cliente::find() y no existeActivo(): hace falta poder cortar el
     * acceso de un cliente que se acaba de dar de baja, no sólo de uno activo.
     */
    private function anularEnlace(): void
    {
        if (!Respuesta::esPost()) {
            Respuesta::error('Se esperaba POST', 405);
            return;
        }

        $cuerpo = Respuesta::cuerpo();
        $clienteId = (int) ($cuerpo['cliente_id'] ?? 0);
        $lote = (string) ($cuerpo['lote'] ?? '');

        if (!Cliente::find($clienteId)) {
            Respuesta::error('El cliente no existe', 404);
            return;
        }

        if (!Enlace::anular($clienteId, $lote)) {
            Respuesta::error('Ese lote no tenía un link activo', 404);
            return;
        }

        Respuesta::ok();
    }

    private function generar(): void
    {
        if (!Respuesta::esPost()) {
            Respuesta::error('Se esperaba POST', 405);
            return;
        }

        $cuerpo = Respuesta::cuerpo();
        $clienteId = (int) ($cuerpo['cliente_id'] ?? 0);
        $cantidad = (int) ($cuerpo['cantidad'] ?? 0);

        if ($cantidad < 1 || $cantidad > self::MAX_POR_TANDA) {
            Respuesta::error('La cantidad tiene que estar entre 1 y ' . self::MAX_POR_TANDA, 400);
            return;
        }

        // La medida es obligatoria: un lote sin medida elegida no se podría
        // grabar sin adivinar con qué tamaño de placa se pensó.
        $tamanoCrudo = $cuerpo['tamano_mm'] ?? null;
        if ($tamanoCrudo === null || $tamanoCrudo === '' || !is_numeric($tamanoCrudo)) {
            Respuesta::error('Elegí la medida del QR en milímetros', 400);
            return;
        }

        $tamanoMm = round((float) $tamanoCrudo, 1);
        if ($tamanoMm < self::MM_MINIMO || $tamanoMm > self::MM_MAXIMO) {
            Respuesta::error(
                'La medida tiene que estar entre ' . self::MM_MINIMO . ' y ' . self::MM_MAXIMO . ' mm',
                400
            );
            return;
        }

        // Se exige que el cliente esté activo: generar seriales para un cliente
        // dado de baja produciría códigos que nadie va a imprimir.
        if (!Cliente::existeActivo($clienteId)) {
            Respuesta::error('El cliente no existe o está dado de baja', 404);
            return;
        }

        $resultado = Serial::generar($clienteId, $cantidad, $tamanoMm, new DateTimeImmutable());

        // Sólo puede pasar si el espacio de códigos está prácticamente agotado.
        if ($resultado['creados'] < $cantidad) {
            error_log(sprintf(
                '[SerialController] generación incompleta: %d de %d (%d colisiones)',
                $resultado['creados'],
                $cantidad,
                $resultado['salteados']
            ));
        }

        if ($resultado['creados'] === 0) {
            Respuesta::error(
                'No se pudo generar ningún serial: todos los códigos sorteados ya existían. '
                . 'Puede que el espacio de ' . number_format(FormatoSerial::combinaciones(), 0, ',', '.')
                . ' combinaciones esté agotado.',
                409
            );
            return;
        }

        // Se manda junto con el resultado para que el JS pueda avisar si la
        // tanda quedó incompleta (agotó la cota de intentos) sin tener que
        // guardar la cantidad pedida por su cuenta.
        Respuesta::datos($resultado + ['pedidos' => $cantidad]);
    }

    /**
     * Da de alta códigos que ya existían afuera, pegados como texto.
     *
     * Se separa en tres resultados y se informan los tres, porque cada uno pide
     * una acción distinta de quien importa: los duplicados avisan que ese código
     * ya estaba (no hay nada que hacer), y los inválidos son los que se quedaron
     * afuera y hay que revisar a mano.
     */
    private function importar(): void
    {
        if (!Respuesta::esPost()) {
            Respuesta::error('Se esperaba POST', 405);
            return;
        }

        $cuerpo = Respuesta::cuerpo();
        $clienteId = (int) ($cuerpo['cliente_id'] ?? 0);
        $texto = (string) ($cuerpo['seriales'] ?? '');

        if (!Cliente::existeActivo($clienteId)) {
            Respuesta::error('El cliente no existe o está dado de baja', 404);
            return;
        }

        $lineas = preg_split('/\R+/', trim($texto)) ?: [];
        $lineas = array_values(array_filter($lineas, static fn($l) => trim($l) !== ''));

        if (empty($lineas)) {
            Respuesta::error('No se pegó ningún código', 400);
            return;
        }

        if (count($lineas) > self::MAX_POR_TANDA) {
            Respuesta::error(
                'Se pueden importar hasta ' . self::MAX_POR_TANDA . ' códigos por vez, y se pegaron '
                . count($lineas) . '. Partilo en tandas más chicas.',
                400
            );
            return;
        }

        $validos = [];
        $invalidos = [];

        foreach ($lineas as $linea) {
            $serial = FormatoSerial::normalizar($linea);

            if (FormatoSerial::esValido($serial)) {
                $validos[] = $serial;
            } else {
                $invalidos[] = $linea;
            }
        }

        if (empty($validos)) {
            Respuesta::error(
                'Ninguno de los ' . count($lineas) . ' códigos tiene el formato esperado ('
                . FormatoSerial::PREFIJO . ' + 6 letras, por ejemplo QRABCDEF).',
                422
            );
            return;
        }

        $resultado = Serial::importar($clienteId, $validos, new DateTimeImmutable());

        Respuesta::datos($resultado + [
            'invalidos' => count($invalidos),
            // Unos pocos ejemplos alcanzan para que se entienda qué se rechazó
            // sin devolver un listado enorme si se pegó cualquier cosa.
            'ejemplos'  => array_slice($invalidos, 0, 5),
        ]);
    }

    /**
     * Hoja imprimible con los QR de un cliente o de una tanda. Vista del
     * panel, detrás de sesión: cliente_id y lote vienen del query string.
     */
    public function qr(): void
    {
        $clienteId = (int) ($_GET['cliente_id'] ?? 0);
        $cliente = Cliente::find($clienteId);

        if (!$cliente) {
            $this->textoPlano(404, 'El cliente no existe');
            return;
        }

        // is_string(): con ?lote[]=x, seleccionar(string $lote) recibiría un
        // array y PHP tiraría TypeError (500) en vez de tratarlo como "todos
        // los lotes".
        $loteCrudo = $_GET['lote'] ?? '';
        $lote = is_string($loteCrudo) ? $loteCrudo : '';

        $seriales = $this->seleccionar($clienteId, $lote);

        $recortado = count($seriales) > self::MAX_POR_HOJA;
        if ($recortado) {
            $seriales = array_slice($seriales, 0, self::MAX_POR_HOJA);
        }

        $config = require BASE_PATH . '/config/app.php';
        $baseQr = $config['qr_base_url'];

        $meta = 'Cliente ' . (int) $cliente['id']
            . ' · ' . ($lote !== '' ? 'Lote ' . $lote : 'Todos los lotes')
            . ' · ' . count($seriales) . ' código' . (count($seriales) === 1 ? '' : 's');

        $params = ['cliente_id' => (int) $cliente['id']];
        if ($lote !== '') {
            $params['lote'] = $lote;
        }
        $qs = http_build_query($params);

        $rutas = [
            'hoja'   => '/qr-hoja.svg?' . $qs,
            'zip'    => '/qr.zip?' . $qs,
            'unidad' => '/qr-unidad.svg?',
        ];

        $this->pintarVista($cliente, $seriales, $recortado, $baseQr, $meta, $rutas, false);
    }

    /**
     * Renderiza la vista de QR imprimible, compartida entre el panel (qr()) y
     * la página pública (grabado()). $meta y $rutas ya vienen armados por
     * quien llama, así la vista no necesita saber en qué modo está -salvo
     * $publico, que sólo decide el <meta name="robots">.
     */
    private function pintarVista(
        array $cliente,
        array $seriales,
        bool $recortado,
        string $baseQr,
        string $meta,
        array $rutas,
        bool $publico
    ): void {
        extract([
            'cliente'   => $cliente,
            'seriales'  => $seriales,
            'recortado' => $recortado,
            'maximo'    => self::MAX_POR_HOJA,
            'baseQr'    => $baseQr,
            'meta'      => $meta,
            'rutas'     => $rutas,
            'publico'   => $publico,
            'logo'      => Cliente::logo((int) $cliente['id']),
        ]);

        header('Content-Type: text/html; charset=utf-8');
        require BASE_PATH . '/app/Views/Seriales/qr.php';
    }

    /**
     * Elige el conjunto de seriales según venga `?lote=` (una tanda generada)
     * o no (todo el histórico generado del cliente). Los importados no entran
     * nunca acá: no producen imagen, así que ni la vista ni ninguna de las dos
     * exportaciones los consideran. Compartido por qr(), zip() y hoja() para no
     * duplicar el if.
     */
    private function seleccionar(int $clienteId, string $lote): array
    {
        return $lote !== ''
            ? Serial::porLote($clienteId, $lote)
            : Serial::porCliente($clienteId);
    }

    /**
     * Una de las dos exportaciones -la otra es hoja()-: los QR de la vista con
     * **un SVG por código**, listo para la grabadora, en milímetros y sin fondo.
     * Es lo que sirve cuando cada código se graba en su propia placa.
     *
     * Van dentro de un .zip porque son muchos archivos, no porque el formato de
     * salida sea otro: lo que se descarga sigue siendo SVG, uno por placa.
     *
     * Se aplica el mismo tope que la vista para que el .zip contenga exactamente
     * lo que se está viendo —si estuviera recortada, la vista ya avisa que hay
     * que filtrar por lote— y porque sortear miles de matrices QR en PHP dentro
     * de un mismo request no termina en un tiempo razonable.
     */
    public function zip(): void
    {
        $pedido = $this->pedidoDeDescarga();

        if ($pedido === null) {
            return;
        }

        $this->emitirZip($pedido);
    }

    /**
     * Misma exportación que zip(), pública: el cliente_id y el lote salen del
     * token del enlace, nunca del query string.
     */
    public function grabadoZip(): void
    {
        $pedido = $this->pedidoPorToken();

        if ($pedido === null) {
            return;
        }

        $this->noIndexar();
        $this->emitirZip($pedido);
    }

    /**
     * @param array{cliente: array, base: string, lote: string, seriales: array, logo: ?\App\Support\QrLogo} $pedido
     */
    private function emitirZip(array $pedido): void
    {
        // Sin filtrar por lote, "todos los lotes" puede mezclar medidas
        // distintas: cada archivo sale con la que se eligió al generar su
        // propia tanda, no con una única medida para todo el zip.
        $archivos = [];
        foreach ($pedido['seriales'] as $fila) {
            $archivos[$fila['serial'] . '.svg'] = QrSvg::paraGrabado(
                FormatoSerial::url($pedido['base'], $fila['serial']),
                $this->mmDe($fila),
                $pedido['logo']
            );
        }

        $zip = Zip::armar($archivos, new DateTimeImmutable());

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'
            . $this->nombreDescarga($pedido['cliente'], $pedido['lote'], '', 'zip') . '"');
        header('Content-Length: ' . strlen($zip));
        // Se arma con QR_BASE_URL, que puede cambiar: no conviene que quede
        // cacheado un zip que apunta al dominio viejo.
        header('Cache-Control: no-store');

        echo $zip;
    }

    /**
     * La otra mitad de la exportación: **los mismos códigos en un solo SVG**,
     * repartidos en hojas A4 verticales, para grabar todas las placas de una
     * pasada en vez de importar un archivo por placa.
     *
     * Es la misma salida de grabado que el .zip -milímetros de verdad, sin
     * fondo blanco, logo en negro, sin una sola letra-, sólo que colocada: cada
     * código entra con la medida de su propio lote, así que una hoja sin
     * filtrar puede mezclar placas de 25 y de 40 mm y cada una sale con la suya.
     *
     * Si no entran todos en una hoja, se apilan más hojas **dentro del mismo
     * archivo**, una debajo de la otra: el SVG mide 210 mm de ancho y un
     * múltiplo exacto de 297 mm de alto, así que cortar cada 297 mm da las
     * páginas. No se dibuja ninguna línea que las separe -sería una figura más
     * para la grabadora, igual que el `<rect>` de fondo que paraGrabado() ya
     * saca-, la división es sólo geométrica.
     */
    public function hoja(): void
    {
        $pedido = $this->pedidoDeDescarga();

        if ($pedido === null) {
            return;
        }

        $this->emitirHoja($pedido);
    }

    /**
     * Misma exportación que hoja(), pública: el cliente_id y el lote salen
     * del token del enlace, nunca del query string.
     */
    public function grabadoHoja(): void
    {
        $pedido = $this->pedidoPorToken();

        if ($pedido === null) {
            return;
        }

        $this->noIndexar();
        $this->emitirHoja($pedido);
    }

    /**
     * @param array{cliente: array, base: string, lote: string, seriales: array, logo: ?\App\Support\QrLogo} $pedido
     */
    private function emitirHoja(array $pedido): void
    {
        [$colocados, $paginas] = $this->distribuir($pedido['seriales']);

        $piezas = '';
        foreach ($colocados as $puesto) {
            $piezas .= QrSvg::enHoja(
                FormatoSerial::url($pedido['base'], $puesto['serial']),
                $puesto['lado'],
                $pedido['logo'],
                $puesto['x'],
                $puesto['y']
            );
        }

        $alto = QrSvg::medida($paginas * self::HOJA_ALTO_MM);
        $ancho = QrSvg::medida(self::HOJA_ANCHO_MM);

        // viewBox en las mismas unidades que el width/height en mm: así una
        // unidad de usuario es un milímetro y las coordenadas que calculó
        // distribuir() se usan tal cual, sin ninguna conversión intermedia.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"'
            . ' width="' . $ancho . 'mm" height="' . $alto . 'mm"'
            . ' viewBox="0 0 ' . $ancho . ' ' . $alto . '">'
            . $piezas
            . '</svg>';

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="'
            . $this->nombreDescarga($pedido['cliente'], $pedido['lote'], 'hoja', 'svg') . '"');
        header('Content-Length: ' . strlen($svg));
        header('Cache-Control: no-store');

        echo $svg;
    }

    /**
     * Lo que las dos exportaciones necesitan y validan igual: el cliente, la
     * URL base, los códigos elegidos y el logo. Devuelve null si ya emitió el
     * error -por eso el llamador sólo tiene que cortar-, para no repetir tres
     * guardas idénticas en zip()/hoja() y en sus pares públicos.
     *
     * **No lee el query string**: recibe cliente y lote ya resueltos. Es lo
     * que permite que las rutas públicas (grabadoZip()/grabadoHoja(), vía
     * pedidoPorToken()) la reutilicen sin poder elegir cliente ni lote -esos
     * dos sólo pueden venir del token.
     *
     * @return array{cliente: array, base: string, lote: string, seriales: array, logo: ?\App\Support\QrLogo}|null
     */
    private function armarPedido(int $clienteId, string $lote): ?array
    {
        $cliente = Cliente::find($clienteId);

        if (!$cliente) {
            $this->textoPlano(404, 'El cliente no existe');
            return null;
        }

        $config = require BASE_PATH . '/config/app.php';
        $base = $config['qr_base_url'];

        // Mismo criterio que /qr.svg: sin la URL base el código no apunta a
        // ningún lado, y grabado en el material no hay forma de corregirlo.
        if ($base === '') {
            $this->textoPlano(409, 'Falta configurar QR_BASE_URL: sin eso el QR no puede apuntar a ningún lado');
            return null;
        }

        $seriales = $this->seleccionar($clienteId, $lote);

        if (empty($seriales)) {
            $this->textoPlano(404, 'No hay códigos para descargar');
            return null;
        }

        return [
            'cliente'  => $cliente,
            'base'     => $base,
            'lote'     => $lote,
            'seriales' => array_slice($seriales, 0, self::MAX_POR_HOJA),
            // Se busca una sola vez, no por serial: es la misma consulta para
            // toda la descarga.
            'logo'     => Cliente::logo($clienteId),
        ];
    }

    /** Panel: cliente_id y lote vienen del query string. */
    private function pedidoDeDescarga(): ?array
    {
        $clienteId = (int) ($_GET['cliente_id'] ?? 0);

        $loteCrudo = $_GET['lote'] ?? '';
        $lote = is_string($loteCrudo) ? $loteCrudo : '';

        return $this->armarPedido($clienteId, $lote);
    }

    /**
     * Público: cliente_id y lote salen del token, nunca del query string. El
     * chequeo de QR_BASE_URL vacío se hace acá aparte -y no dentro de
     * armarPedido()- porque el mensaje ahí ("falta configurar QR_BASE_URL")
     * es una instrucción para el administrador y no tiene por qué llegarle a
     * un tercero sin cuenta.
     */
    private function pedidoPorToken(): ?array
    {
        $enlace = $this->resolverToken();
        if ($enlace === null) {
            return null;
        }

        $config = require BASE_PATH . '/config/app.php';
        if ($config['qr_base_url'] === '') {
            $this->textoPlano(503, 'Los códigos todavía no están disponibles. Escribile a quien te pasó este enlace.');
            return null;
        }

        return $this->armarPedido($enlace['cliente_id'], $enlace['lote']);
    }

    /**
     * Resuelve un token a su lote. null ⇒ ya se emitió el 404 (token con
     * formato roto, inexistente o anulado: los tres casos responden
     * exactamente igual, no se filtra si el token llegó a existir).
     *
     * $token viene del path en la página pública (la raíz del dominio) y de
     * ?t= en las tres descargas, que se clickean desde esa página y no se
     * comparten. Sin argumento, se lee ?t=.
     *
     * @return array{cliente_id:int, lote:string}|null
     */
    private function resolverToken(?string $token = null): ?array
    {
        if ($token === null) {
            $crudo = $_GET['t'] ?? '';

            // is_string(): con ?t[]=x, FormatoEnlace::normalizar(string)
            // recibiría un array y tiraría TypeError -un 500 donde tiene que
            // haber un 404.
            $token = is_string($crudo) ? FormatoEnlace::normalizar($crudo) : '';
        }

        $enlace = Enlace::porToken($token);

        if ($enlace === null) {
            $this->noEncontrado();
            return null;
        }

        return $enlace;
    }

    private function noEncontrado(): void
    {
        // El token son ~34 bits (ver FormatoEnlace), no los ~98 de un token
        // largo: acertar uno al azar sigue siendo impracticable, pero ya no
        // por márgenes absurdos. Este retardo es el mismo criterio que el de
        // AuthController ante contraseña incorrecta -no reemplaza a un bloqueo
        // por intentos, encarece la fuerza bruta.
        //
        // Va acá y no en resolverToken() a propósito: así también retarda el
        // 404 de un token válido cuyo lote quedó vacío, que es lo que los deja
        // indistinguibles por tiempo además de por cuerpo.
        usleep(300000);

        $this->textoPlano(404, 'Enlace no válido');
    }

    /**
     * Cabeceras de las cuatro rutas públicas (/grabado*): no se indexan, y el
     * token no viaja si la página llegara a linkear afuera.
     */
    private function noIndexar(): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
    }

    /**
     * La página pública de un lote: lo que se le pasa a la grabadora. Es la
     * ruta en la raíz del dominio (qr.example.com/WEQEWRW), así que el token
     * llega ya reconocido desde public/index.php; no lee cliente_id ni lote
     * del query string en ningún caso. Responde el mismo 404 que un token
     * roto ante cualquier otro motivo (lote sin códigos, cliente borrado de
     * la base). El cliente dado de baja SÍ se sirve: la baja significa "no se
     * generan códigos nuevos", no "cortar los enlaces ya emitidos" -para eso
     * está "Anular" en el panel.
     */
    public function grabado(string $token): void
    {
        $enlace = $this->resolverToken($token);
        if ($enlace === null) {
            return;
        }

        $cliente = Cliente::find($enlace['cliente_id']);
        $config = require BASE_PATH . '/config/app.php';
        $baseQr = $config['qr_base_url'];

        if ($baseQr === '') {
            $this->textoPlano(503, 'Los códigos todavía no están disponibles. Escribile a quien te pasó este enlace.');
            return;
        }

        $seriales = $cliente !== null ? Serial::porLote($enlace['cliente_id'], $enlace['lote']) : [];

        if ($cliente === null || empty($seriales)) {
            $this->noEncontrado();
            return;
        }

        $recortado = count($seriales) > self::MAX_POR_HOJA;
        if ($recortado) {
            $seriales = array_slice($seriales, 0, self::MAX_POR_HOJA);
        }

        // El valor efectivo -no la columna cruda-, porque en un lote anterior
        // a tamano_mm es NULL y lo que se muestra tiene que ser lo que
        // realmente contienen los archivos (25 mm, el fallback). Un lote
        // entero comparte medida: se genera con una sola llamada.
        $mm = $this->mmDe($seriales[0]);
        $meta = count($seriales) . ' código' . (count($seriales) === 1 ? '' : 's') . ' · ' . $mm . ' mm';

        // Las tres descargas siguen llevando el token en ?t= y no en el path.
        // No se tipean ni se comparten -se clickean desde acá, y las tres
        // bajan como attachment sin cambiar la barra de direcciones-, así que
        // la forma corta sólo hace falta en la URL de esta página.
        $t = urlencode($token);
        $rutas = [
            'hoja'   => '/grabado-hoja.svg?t=' . $t,
            'zip'    => '/grabado.zip?t=' . $t,
            'unidad' => '/grabado-unidad.svg?t=' . $t . '&',
        ];

        $this->noIndexar();
        $this->pintarVista($cliente, $seriales, $recortado, $baseQr, $meta, $rutas, true);
    }

    /**
     * Descarga individual, panel: sin cliente_id porque el serial es único
     * global y ya determina cliente y tamano_mm; la ruta está detrás de
     * sesión, así que no hace falta más contexto.
     */
    public function unidad(): void
    {
        $serial = FormatoSerial::normalizar((string) ($_GET['serial'] ?? ''));

        if (!FormatoSerial::esValido($serial)) {
            $this->textoPlano(400, 'Serial con formato inválido');
            return;
        }

        $config = require BASE_PATH . '/config/app.php';
        $base = $config['qr_base_url'];

        if ($base === '') {
            $this->textoPlano(409, 'Falta configurar QR_BASE_URL: sin eso el QR no puede apuntar a ningún lado');
            return;
        }

        $fila = Serial::buscar($serial);

        // origen 'generado' obligatorio: sin este filtro, esta ruta sería la
        // única forma de sacarle un archivo de grabado a un código importado,
        // que por diseño no produce imagen (ver "Importar códigos que ya
        // existían" en CLAUDE.md).
        if ($fila === null || $fila['origen'] !== 'generado') {
            $this->textoPlano(404, 'El serial no existe');
            return;
        }

        $this->emitirUnidad($fila, $base);
    }

    /**
     * Descarga individual, pública: el serial tiene que pertenecer al lote
     * del token -no alcanza con que exista-, si no un link válido serviría
     * para bajar cualquier código de cualquier cliente con sólo cambiar
     * ?serial=.
     */
    public function grabadoUnidad(): void
    {
        $enlace = $this->resolverToken();
        if ($enlace === null) {
            return;
        }

        $config = require BASE_PATH . '/config/app.php';
        $base = $config['qr_base_url'];

        if ($base === '') {
            $this->textoPlano(503, 'Los códigos todavía no están disponibles. Escribile a quien te pasó este enlace.');
            return;
        }

        $serialCrudo = $_GET['serial'] ?? '';
        $serial = is_string($serialCrudo) ? FormatoSerial::normalizar($serialCrudo) : '';

        $fila = FormatoSerial::esValido($serial)
            ? Serial::buscarEnLote($enlace['cliente_id'], $enlace['lote'], $serial)
            : null;

        if ($fila === null) {
            $this->noEncontrado();
            return;
        }

        $this->noIndexar();
        $this->emitirUnidad($fila, $base);
    }

    /**
     * @param array{serial:string, cliente_id:int, tamano_mm:?string} $fila
     */
    private function emitirUnidad(array $fila, string $base): void
    {
        $svg = QrSvg::paraGrabado(
            FormatoSerial::url($base, $fila['serial']),
            $this->mmDe($fila),
            Cliente::logo($fila['cliente_id'])
        );

        header('Content-Type: image/svg+xml; charset=utf-8');
        // El serial ya está validado contra ^QR[A-Z]{6}$: a diferencia de
        // nombreDescarga(), no hace falta tramoSeguro() acá, no hay forma de
        // inyectar un salto de línea en la cabecera.
        header('Content-Disposition: attachment; filename="' . $fila['serial'] . '.svg"');
        header('Content-Length: ' . strlen($svg));
        header('Cache-Control: no-store');

        echo $svg;
    }

    /**
     * Reparte las placas sobre hojas A4 verticales, de izquierda a derecha y de
     * arriba abajo, y devuelve dónde va cada una en milímetros absolutos del
     * archivo (o sea, con el desplazamiento de su página ya sumado).
     *
     * Las filas no tienen una altura fija: un mismo pedido puede traer lotes de
     * medidas distintas, así que cada fila alta lo que la placa más alta que le
     * tocó. Se acomodan en el orden en que vienen -que es el de generación- y no
     * se reordenan por tamaño: el orden del archivo es el mismo que el de la
     * hoja de control, y buscar una placa concreta ahí adentro es más fácil que
     * ganar un poco de aprovechamiento del material.
     *
     * @param array<int, array> $seriales
     * @return array{0: array<int, array{serial: string, lado: float, x: float, y: float}>, 1: int}
     */
    private function distribuir(array $seriales): array
    {
        $utilAncho = self::HOJA_ANCHO_MM - 2 * self::HOJA_MARGEN_MM;
        $utilAlto = self::HOJA_ALTO_MM - 2 * self::HOJA_MARGEN_MM;

        $colocados = [];
        $pagina = 0;
        $x = 0.0;         // desplazamiento dentro del área útil de la página
        $y = 0.0;
        $altoFila = 0.0;

        foreach ($seriales as $fila) {
            $lado = $this->mmDe($fila);

            // Salto de fila. La guarda de $x > 0 es la que evita el bucle
            // infinito con una placa más ancha que la hoja: en vez de no
            // entrar nunca, se coloca igual y desborda el margen.
            if ($x > 0.0 && $x + $lado > $utilAncho + self::HOJA_EPSILON) {
                $y += $altoFila + self::HOJA_SEPARACION_MM;
                $x = 0.0;
                $altoFila = 0.0;
            }

            // Salto de página, con la misma guarda: una placa que no entra ni
            // en una hoja vacía no gana nada empujándola a la siguiente.
            if ($y > 0.0 && $y + $lado > $utilAlto + self::HOJA_EPSILON) {
                $pagina++;
                $x = 0.0;
                $y = 0.0;
                $altoFila = 0.0;
            }

            $colocados[] = [
                'serial' => $fila['serial'],
                'lado'   => $lado,
                'x'      => self::HOJA_MARGEN_MM + $x,
                'y'      => $pagina * self::HOJA_ALTO_MM + self::HOJA_MARGEN_MM + $y,
            ];

            $x += $lado + self::HOJA_SEPARACION_MM;
            $altoFila = max($altoFila, $lado);
        }

        return [$colocados, $pagina + 1];
    }

    /**
     * Lado en mm de la placa de un serial. NULL sólo en las filas generadas
     * antes de que existiera la columna: se emiten con los 25 mm que se venía
     * usando, que es exactamente lo que se descargaba antes de este cambio.
     */
    private function mmDe(array $fila): float
    {
        return isset($fila['tamano_mm'])
            ? (float) $fila['tamano_mm']
            : self::MM_POR_DEFECTO;
    }

    /**
     * Nombre del archivo que se baja. Sale de datos que vienen del usuario y de
     * la query string, y termina en una cabecera HTTP: se deja sólo
     * [A-Za-z0-9._-] para que no haya forma de inyectar un salto de línea ahí
     * adentro.
     *
     * @param array{id:int, nombre:string} $cliente
     * @param string $sufijo    distingue las dos exportaciones del mismo lote
     * @param string $extension sin el punto
     */
    private function nombreDescarga(array $cliente, string $lote, string $sufijo, string $extension): string
    {
        // Los nombres son en español: sin esto, "Muñoz" quedaría "mu-oz".
        $sinAcentos = strtr($cliente['nombre'], [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);

        $partes = ['qr', $this->tramoSeguro($sinAcentos) ?: 'cliente-' . (int) $cliente['id']];
        $partes[] = $lote !== '' ? ($this->tramoSeguro($lote) ?: 'lote') : 'todos';

        if ($sufijo !== '') {
            $partes[] = $sufijo;
        }

        return implode('-', $partes) . '.' . $extension;
    }

    private function tramoSeguro(string $texto): string
    {
        $limpio = preg_replace('/[^A-Za-z0-9]+/', '-', $texto) ?? '';

        return substr(trim(strtolower($limpio), '-'), 0, 60);
    }

    private function textoPlano(int $codigo, string $mensaje): void
    {
        http_response_code($codigo);
        header('Content-Type: text/plain; charset=utf-8');
        echo $mensaje;
    }
}
