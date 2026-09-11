<?php

namespace App\Support;

/**
 * Logo que se incrusta en el centro del QR (ver QrSvg).
 *
 * Objeto de valor: un viewBox (ya parseado, no el string crudo) más el markup
 * de geometría que va adentro. El contrato deliberado no cambió con el logo
 * por cliente — sigue siendo geometría monocroma, con el color impuesto desde
 * afuera (negro, ver COLOR) — lo que cambió es de dónde
 * sale: hoy el arte vive en `qr_clientes.logo_svg`/`logo_viewbox`, saneado por
 * SaneadorSvg al subirse, y esta clase es sólo el objeto que lo representa más
 * el placeholder para los clientes que todavía no cargaron uno.
 */
final class QrLogo
{
    /**
     * Color del logo, el mismo en todas las salidas: **negro**, igual que los
     * módulos del código. En grabado no hay otra opción -la máquina graba una
     * figura, no un color-, y en pantalla/papel se usa el mismo para que lo que
     * se ve en `/qr` sea exactamente lo que después sale de la grabadora.
     */
    public const COLOR = '#000000';

    public function __construct(
        public readonly float $minX,
        public readonly float $minY,
        public readonly float $ancho,
        public readonly float $alto,
        public readonly string $markup
    ) {
    }

    /**
     * Placeholder para los clientes que todavía no subieron un logo propio:
     * el isotipo de los favicons, reexpresado como un único path. Cuadrado
     * redondeado de fondo + las cinco marcas del isotipo original como
     * huecos (fill-rule=evenodd), en vez de blanco pintado encima — así
     * funciona igual a color y en negro puro.
     */
    public static function porDefecto(): self
    {
        return new self(0, 0, 16, 16, '<path fill-rule="evenodd" d="'
            . 'M3 0h10a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3H3a3 3 0 0 1-3-3V3a3 3 0 0 1 3-3z'
            . 'M3.5 3.5h3v3h-3zM9.5 3.5h3v3h-3zM3.5 9.5h3v3h-3z'
            . 'M8.5 8.5h1.5v1.5H8.5zM11 11h1.5v1.5H11z'
            . '"/>');
    }

    /**
     * Reconstruye el logo guardado en `qr_clientes` a partir del viewBox de
     * texto y el markup ya saneado. Tira InvalidArgumentException si el
     * viewBox no son cuatro números finitos con ancho y alto positivos — una
     * fila corrupta no debe llegar a romper el render, así que el llamador
     * (Cliente::logo) atrapa esto y cae al placeholder.
     */
    public static function desdeBase(string $viewbox, string $markup): self
    {
        $partes = preg_split('/[\s,]+/', trim($viewbox)) ?: [];

        if (count($partes) !== 4 || !array_reduce($partes, static fn($ok, $p) => $ok && is_numeric($p), true)) {
            throw new \InvalidArgumentException('El viewBox guardado no tiene el formato "minX minY ancho alto"');
        }

        [$minX, $minY, $ancho, $alto] = array_map('floatval', $partes);

        if (!is_finite($minX) || !is_finite($minY) || $ancho <= 0 || $alto <= 0 || !is_finite($ancho) || !is_finite($alto)) {
            throw new \InvalidArgumentException('El viewBox guardado tiene valores inválidos');
        }

        return new self($minX, $minY, $ancho, $alto, $markup);
    }

    public function viewBoxString(): string
    {
        return $this->minX . ' ' . $this->minY . ' ' . $this->ancho . ' ' . $this->alto;
    }
}
