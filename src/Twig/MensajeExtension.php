<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;

/**
 * Traduce mensajes con un parámetro codificados como "clave|valor" (los usa el importador),
 * por ejemplo "importacion.error_repetido|12" → "Repetido: la misma URL está en la fila 12".
 */
final class MensajeExtension
{
    public function __construct(private readonly TranslatorInterface $traductor)
    {
    }

    #[AsTwigFilter('mensaje')]
    public function mensaje(string $codigo): string
    {
        [$clave, $valor] = explode('|', $codigo, 2) + [1 => ''];

        return $this->traductor->trans($clave, ['%valor%' => $valor]);
    }
}
