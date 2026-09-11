<?php

namespace App\Support;

/**
 * Autenticación del panel: una sola contraseña, sin tabla de usuarios.
 *
 * En el entorno no va la contraseña sino su hash bcrypt en base64, así que
 * quien lea el .env o las variables de EasyPanel no se lleva la clave. Ver
 * config/app.php para el porqué del base64 y el comando que genera el valor.
 *
 * La sesión se recuerda por 30 días con una cookie firmada aparte, para no
 * tener que escribir la contraseña en cada visita. El detalle está en
 * recordar() / desdeCookieRecordada().
 */
class Auth
{
    private const CLAVE_SESION = 'neo_qr_autenticado';

    /** Nombre de la cookie que recuerda la sesión entre visitas. */
    private const COOKIE_RECORDADO = 'neoqr_recordado';

    /** Cuánto dura el recuerdo. Se renueva en cada visita. */
    private const DIAS_RECORDADO = 30;

    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Sin esto PHP recicla las sesiones inactivas a los ~24 minutos y el
        // panel pediría la clave de nuevo en medio de la jornada.
        $segundos = self::DIAS_RECORDADO * 86400;
        ini_set('session.gc_maxlifetime', (string) $segundos);

        session_set_cookie_params([
            'lifetime' => $segundos,
            'path'     => '/',
            'httponly' => true,                 // el JS no necesita leerla
            'secure'   => self::esHttps(),
            // SameSite=Strict hace que el navegador no mande la cookie en
            // requests que vengan de otro sitio. Es lo que evita que una página
            // ajena dispare un POST contra la API con la sesión del usuario;
            // sin esto haría falta un token CSRF aparte.
            'samesite' => 'Strict',
        ]);

        session_name('neoqr');
        session_start();
    }

    public static function autenticado(): bool
    {
        self::iniciar();

        $autenticado = !empty($_SESSION[self::CLAVE_SESION]);

        // La sesión de PHP vive en el disco del contenedor, así que cada deploy
        // la borra. Por eso el recuerdo no se apoya en ella: si la cookie
        // firmada es válida, se reconstruye la sesión y el usuario ni se entera.
        if (!$autenticado && self::desdeCookieRecordada()) {
            $_SESSION[self::CLAVE_SESION] = true;
            $autenticado = true;
        }

        if ($autenticado) {
            // Se corre el vencimiento en cada visita. Sin esto el recuerdo
            // caducaría a los 30 días del login aunque el panel se use a
            // diario, que no es lo que se espera de "quedar guardada".
            self::recordar();
        }

        return $autenticado;
    }

    /** false si todavía no se cargó ADMIN_PASSWORD_HASH_B64 en el entorno. */
    public static function configurada(): bool
    {
        return self::hash() !== '';
    }

    public static function ingresar(string $password): bool
    {
        $hash = self::hash();

        // password_verify ya compara en tiempo constante, así que no hace falta
        // hash_equals encima.
        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }

        self::iniciar();
        // Renovar el id al autenticarse evita la fijación de sesión: si alguien
        // logró plantar un id antes del login, ese id deja de servir.
        session_regenerate_id(true);
        $_SESSION[self::CLAVE_SESION] = true;
        self::recordar();

        return true;
    }

    public static function salir(): void
    {
        self::iniciar();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        // Sin borrar también la cookie de recuerdo, "Salir" no serviría de nada:
        // el próximo request la reconstruiría.
        setcookie(self::COOKIE_RECORDADO, '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'httponly' => true,
            'secure'   => self::esHttps(),
            'samesite' => 'Strict',
        ]);

        session_destroy();
    }

    /**
     * Cookie firmada con el formato "<vence>.<firma>".
     *
     * No guarda nada del lado del servidor a propósito: el contenedor se
     * reemplaza en cada deploy y cualquier cosa que dependiera del disco se
     * perdería. La firma es un HMAC con **el hash de la contraseña como clave**,
     * lo que da gratis una propiedad útil: al cambiar la contraseña cambia la
     * clave, y todos los dispositivos recordados quedan invalidados de una.
     */
    private static function recordar(): void
    {
        $hash = self::hash();
        if ($hash === '') {
            return;
        }

        $vence = time() + self::DIAS_RECORDADO * 86400;

        setcookie(self::COOKIE_RECORDADO, $vence . '.' . self::firmar($vence), [
            'expires'  => $vence,
            'path'     => '/',
            'httponly' => true,
            'secure'   => self::esHttps(),
            'samesite' => 'Strict',
        ]);
    }

    private static function desdeCookieRecordada(): bool
    {
        $cookie = $_COOKIE[self::COOKIE_RECORDADO] ?? '';
        if (!is_string($cookie) || !str_contains($cookie, '.')) {
            return false;
        }

        [$vence, $firma] = explode('.', $cookie, 2);

        if (!ctype_digit($vence) || (int) $vence < time()) {
            return false;
        }

        // hash_equals compara en tiempo constante: con == se podría deducir la
        // firma correcta midiendo cuánto tarda en responder.
        return self::hash() !== '' && hash_equals(self::firmar((int) $vence), $firma);
    }

    private static function firmar(int $vence): string
    {
        return hash_hmac('sha256', (string) $vence, self::hash());
    }

    private static function hash(): string
    {
        $config = require BASE_PATH . '/config/app.php';

        return $config['admin_password_hash'];
    }

    /**
     * EasyPanel termina TLS en su proxy y le habla HTTP a la app, así que
     * $_SERVER['HTTPS'] viene vacío y el único indicio real es el header que
     * reenvía el proxy. Sin esto la cookie nunca se marcaría como segura.
     */
    private static function esHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
