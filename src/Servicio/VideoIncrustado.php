<?php

declare(strict_types=1);

namespace App\Servicio;

use Twig\Attribute\AsTwigFunction;

/**
 * Convierte el enlace de un video (YouTube o Vimeo) en la dirección de su reproductor incrustable.
 * YouTube se incrusta en modo de privacidad mejorada (youtube-nocookie.com).
 */
final class VideoIncrustado
{
    private const array PATRONES = [
        '~^https?://(?:www\.|m\.)?youtube\.com/watch\?(?:.*&)?v=([\w-]{11})~i' => 'https://www.youtube-nocookie.com/embed/%s',
        '~^https?://(?:www\.)?youtube\.com/(?:embed|shorts|live)/([\w-]{11})~i' => 'https://www.youtube-nocookie.com/embed/%s',
        '~^https?://youtu\.be/([\w-]{11})~i' => 'https://www.youtube-nocookie.com/embed/%s',
        '~^https?://(?:www\.)?vimeo\.com/(?:video/)?(\d+)~i' => 'https://player.vimeo.com/video/%s',
        '~^https?://player\.vimeo\.com/video/(\d+)~i' => 'https://player.vimeo.com/video/%s',
    ];

    #[AsTwigFunction('video_incrustado')]
    public function direccion(?string $url): ?string
    {
        foreach (self::PATRONES as $patron => $formato) {
            if (null !== $url && 1 === preg_match($patron, $url, $coincidencia)) {
                return \sprintf($formato, $coincidencia[1]);
            }
        }

        return null;
    }
}
