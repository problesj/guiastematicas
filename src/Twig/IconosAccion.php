<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * Iconos de las acciones (Editar, Eliminar, Ver, Mover, Incrustar, Cancelar...). El enlace o botón lleva la clase
 * "accion" y data-tooltip con la leyenda (se muestra al pasar el mouse o al llegar con el teclado); el texto para lectores
 * de pantalla va en un span .solo-lectores. Los mismos trazos están en assets/controllers/selector_lista_controller.js.
 */
final class IconosAccion
{
    public const array TRAZOS = [
        'editar' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M14 6l4 4"/>',
        'eliminar' => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/>',
        'ver' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'mover' => '<path d="M12 3v18M3 12h18M9 6l3-3 3 3M9 18l3 3 3-3M6 9l-3 3 3 3M18 9l3 3-3 3"/>',
        'incrustar' => '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4M14 5l-4 14"/>',
        'cancelar' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'guardar' => '<path d="M5 4h11l3 3v13H5z"/><path d="M8 4v5h7V4M8 20v-6h8v6"/>',
        'revisar' => '<path d="M9 4h6v3H9z"/><path d="M15 5h3v16H6V5h3M9 13l2 2 4-4"/>',
        'responder' => '<path d="M10 8L4 13l6 5v-3c5 0 8 1 10 4-1-5-4-9-10-10z"/>',
        'subir' => '<path d="M12 19V5M6 11l6-6 6 6"/>',
        'bajar' => '<path d="M12 5v14M6 13l6 6 6-6"/>',
        'vincular' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'desvincular' => '<path d="M10 14a4 4 0 0 0 5.7 0l1.3-1.3M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7M13 5.3l.4-.4a4 4 0 0 1 5.7 5.7l-.4.4"/><path d="M3 3l18 18"/>',
        'copiar' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a1 1 0 0 1 1-1h9"/>',
    ];

    #[AsTwigFunction('icono', isSafe: ['html'])]
    public function icono(string $nombre): string
    {
        return '<svg class="accion__icono" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            .(self::TRAZOS[$nombre] ?? throw new \InvalidArgumentException('Icono desconocido: '.$nombre)).'</svg>';
    }
}
