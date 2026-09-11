<?php

// Todas las credenciales vienen de variables de entorno (EasyPanel).
// Sin defaults hardcodeados: si falta alguna, se aborta explícitamente
// en vez de arrancar con un valor incorrecto o expuesto en el repo.

$required = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
$missing = [];

foreach ($required as $key) {
    if (getenv($key) === false || getenv($key) === '') {
        $missing[] = $key;
    }
}

if (!empty($missing)) {
    $message = 'Faltan variables de entorno de base de datos: ' . implode(', ', $missing);
    error_log('[database.php] ' . $message);
    http_response_code(500);
    exit($message);
}

return [
    'driver'   => 'pgsql',
    'host'     => getenv('DB_HOST'),
    'port'     => getenv('DB_PORT'),
    'database' => getenv('DB_DATABASE'),
    'username' => getenv('DB_USERNAME'),
    'password' => getenv('DB_PASSWORD'),
];
