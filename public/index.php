<?php

define('BASE_PATH', dirname(__DIR__));

// Cargar variables desde .env (si existe). El entorno real (EasyPanel) tiene prioridad.
$envFile = BASE_PATH . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        // Quitar comillas envolventes si las hay
        if (strlen($val) >= 2 && (($val[0] === '"' && $val[-1] === '"') || ($val[0] === "'" && $val[-1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

// Autoload PSR-4 sobre el prefijo App\ (mismo criterio que el proyecto previo)
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = BASE_PATH . '/app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Router
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = rtrim($requestUri, '/') ?: '/';

// Todos los paths que este switch atiende por nombre. Es la lista contra la
// que se descarta que un path sea un token de enlace, más abajo; si se agrega
// un case, va también acá.
$rutasEstaticas = [
    '/health', '/login', '/logout',
    '/', '/clientes', '/clientes/nuevo',
    '/api/clientes', '/api/seriales',
    '/qr', '/qr.zip', '/qr-hoja.svg', '/qr.svg', '/qr-unidad.svg',
    '/grabado.zip', '/grabado-hoja.svg', '/grabado-unidad.svg',
];

// El link público de un lote va en la raíz del dominio -por ejemplo
// qr.example.com/WEQEWRW-, así que no puede ser un case del switch: se
// reconoce por forma (ver FormatoEnlace::deRuta y "Link para la grabadora"
// en CLAUDE.md).
//
// Se descarta primero contra $rutasEstaticas para que agregar mañana una ruta
// de 7 caracteres del alfabeto del token no la vuelva pública sin querer. Hoy
// ninguna colisiona -todas son más cortas, más largas, o llevan un punto, un
// guión o una barra, que no están en ese alfabeto-, pero el guard no puede
// depender de que eso siga siendo cierto.
$tokenRuta = in_array($requestUri, $rutasEstaticas, true)
    ? null
    : \App\Support\FormatoEnlace::deRuta($requestUri);

// El panel entero está detrás de la contraseña. Quedan afuera el healthcheck
// (lo consume Docker, sin sesión), el propio login, y las rutas del link
// público de la grabadora: la página en la raíz ($tokenRuta) y sus tres
// descargas. Esas son públicas porque el token ES la credencial, no la sesión.
// /qr-unidad.svg NO va acá: es la descarga individual del panel, y sigue
// detrás de sesión.
$rutasPublicas = [
    '/health', '/login', '/logout',
    '/grabado.zip', '/grabado-hoja.svg', '/grabado-unidad.svg',
];

if ($tokenRuta === null && !in_array($requestUri, $rutasPublicas, true) && !\App\Support\Auth::autenticado()) {
    if (str_starts_with($requestUri, '/api/')) {
        // A un fetch se le responde 401 y el JS redirige; mandarle un 302 a una
        // página HTML sólo produciría un error confuso.
        \App\Support\Respuesta::error('Sesión no iniciada', 401);
    } else {
        http_response_code(302);
        header('Location: /login');
    }
    return;
}

try {
    switch ($requestUri) {
        // Lo consume el HEALTHCHECK del Dockerfile. Verifica también la conexión
        // a PostgreSQL, no sólo que PHP responda.
        case '/health':
            (new \App\Controllers\HealthController())->check();
            break;

        // GET muestra el formulario, POST intenta el ingreso.
        case '/login':
            (new \App\Controllers\AuthController())->login();
            break;

        case '/logout':
            (new \App\Controllers\AuthController())->salir();
            break;

        case '/':
        case '/clientes':
            (new \App\Controllers\ClienteController())->index();
            break;

        case '/clientes/nuevo':
            (new \App\Controllers\ClienteController())->nuevo();
            break;

        case '/api/clientes':
            (new \App\Controllers\ClienteController())->api();
            break;

        case '/api/seriales':
            (new \App\Controllers\SerialController())->api();
            break;

        // La hoja imprimible con todos los QR de un cliente o de un lote.
        // Hermana de /qr.svg, que devuelve un único código como imagen.
        case '/qr':
            (new \App\Controllers\SerialController())->qr();
            break;

        // Las dos exportaciones de los códigos que muestra /qr, las dos en SVG
        // preparado para la grabadora: separados (un archivo por placa, dentro
        // de un .zip porque son muchos) o todos juntos en una hoja A4.
        case '/qr.zip':
            (new \App\Controllers\SerialController())->zip();
            break;

        case '/qr-hoja.svg':
            (new \App\Controllers\SerialController())->hoja();
            break;

        case '/qr.svg':
            (new \App\Controllers\QrController())->svg();
            break;

        // Descarga de un código suelto, panel (detrás de sesión). Hermana de
        // /grabado-unidad.svg, la misma descarga pero pública.
        case '/qr-unidad.svg':
            (new \App\Controllers\SerialController())->unidad();
            break;

        // Las tres descargas del link público de la grabadora: sin sesión, con
        // cliente y lote resueltos únicamente a partir de ?t= (ver
        // $rutasPublicas más arriba y "Link para la grabadora" en CLAUDE.md).
        // La página que las ofrece no está acá: va en la raíz, en el default.
        case '/grabado.zip':
            (new \App\Controllers\SerialController())->grabadoZip();
            break;

        case '/grabado-hoja.svg':
            (new \App\Controllers\SerialController())->grabadoHoja();
            break;

        case '/grabado-unidad.svg':
            (new \App\Controllers\SerialController())->grabadoUnidad();
            break;

        // Lo que no tiene nombre propio se resuelve por forma. Hoy sólo el
        // link público de la grabadora (7 símbolos); si algún día la app
        // tiene que atender /QRABCDEF -lo que abre el celular al escanear-,
        // es acá, con FormatoSerial::esValido() al lado de este if. Los dos
        // formatos no se pisan: 8 caracteres con prefijo QR contra 7 de un
        // alfabeto sin I, L, O ni U.
        default:
            if ($tokenRuta !== null) {
                (new \App\Controllers\SerialController())->grabado($tokenRuta);
                break;
            }

            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404 - No encontrado';
    }
} catch (\Throwable $e) {
    // Red de seguridad: los controllers ya manejan lo suyo, pero si algo se
    // escapa no se le muestra al usuario el mensaje crudo de PDO.
    error_log('[index] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo '500 - Error interno';
}
