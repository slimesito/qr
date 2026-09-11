<?php

namespace App\Controllers;

use App\Support\Auth;

/**
 * Login del panel. Una contraseña, sin usuarios.
 */
class AuthController
{
    /**
     * Atiende GET (formulario) y POST (intento de ingreso) sobre /login.
     */
    public function login(): void
    {
        if (Auth::autenticado()) {
            $this->redirigir('/clientes');
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->formulario();
            return;
        }

        if (Auth::ingresar((string) ($_POST['password'] ?? ''))) {
            $this->redirigir('/clientes');
            return;
        }

        // Retardo deliberado: encarece probar contraseñas de a una. No reemplaza
        // a un bloqueo por intentos, pero con una sola clave y un panel interno
        // alcanza para que la fuerza bruta no sea gratis.
        usleep(400000);

        $this->formulario('Contraseña incorrecta');
    }

    public function salir(): void
    {
        Auth::salir();
        $this->redirigir('/login');
    }

    private function formulario(?string $error = null): void
    {
        $contenido = $this->render('Auth/login', [
            'error'       => $error,
            'configurada' => Auth::configurada(),
        ]);

        echo $this->render('Layouts/app', [
            'titulo'      => 'Ingresar',
            'contenido'   => $contenido,
            'scripts'     => [],
            'autenticado' => false,
        ]);
    }

    private function redirigir(string $destino): void
    {
        http_response_code(302);
        header('Location: ' . $destino);
    }

    private function render(string $vista, array $datos = []): string
    {
        extract($datos);
        ob_start();
        require BASE_PATH . '/app/Views/' . $vista . '.php';

        return ob_get_clean();
    }
}
