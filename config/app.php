<?php

// La URL base que se codifica dentro del QR. Lo que se imprime es
// {qr_base_url}/{serial}, no el serial pelado: un serial suelto no le sirve de
// nada a un celular que escanea.
//
// Sin QR_BASE_URL no se puede generar una imagen definitiva, porque la URL queda
// embebida en el QR impreso y después no hay forma de corregirla.
$qrBaseUrl = getenv('QR_BASE_URL');

// Hash bcrypt de la contraseña del panel, guardado en base64 en el entorno.
//
// Un hash bcrypt arranca con "$2y$10$..." y algunos sistemas de variables de
// entorno (Docker Compose entre ellos) interpretan un "$" como el inicio de
// otra variable y truncan o vacían el valor sin avisar. Envolverlo en base64
// evita el problema de raíz: el string que queda en el .env no tiene ningún
// carácter especial. Se genera con:
//
//   php -r 'echo base64_encode(password_hash("la-que-elijas", PASSWORD_DEFAULT)), "\n";'
$passwordHashB64 = getenv('ADMIN_PASSWORD_HASH_B64');
$passwordHash = is_string($passwordHashB64) && trim($passwordHashB64) !== ''
    ? base64_decode(trim($passwordHashB64), true)
    : false;

return [
    'name'                => 'neo-qr',
    // Versión de la app, la que muestra el pie del panel. Se sube a mano en el
    // mismo commit que la cambia; no se deriva de git porque .dockerignore deja
    // .git afuera de la imagen y en producción no hay repo del que leerla. Es
    // distinta del sello de deploy (.build, que escribe el Dockerfile): ese dice
    // cuándo se construyó la imagen, esta dice qué versión del código trae.
    'version'             => '1.0.0',
    'url'                 => getenv('APP_URL') ?: '',
    'qr_base_url'         => is_string($qrBaseUrl) ? rtrim($qrBaseUrl, '/') : '',
    // base64_decode(..., true) devuelve false si el string no es base64 válido
    // (por ejemplo, si alguien pegó el hash sin codificar por error). Ante
    // duda se prefiere dejar vacío -y bloquear el login- antes que arrancar
    // con un hash corrupto que compare siempre falso de una forma menos obvia.
    'admin_password_hash' => is_string($passwordHash) ? $passwordHash : '',
];
