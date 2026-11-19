<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Cabeceras de seguridad en todas las respuestas, aunque el servidor web no las ponga (Apache las repite en
 * deploy/apache/guias.conf). No reemplaza las que ya trae la respuesta: los bloques incrustables y las páginas de
 * Moodle (LTI) definen su propia Content-Security-Policy para poder mostrarse en otros sitios.
 */
#[AsEventListener]
final class CabecerasSeguridadSubscriber
{
    /** Sin plugins (object), sin cambiar la base de las URL y, salvo lo indicado, sin mostrarse dentro de otra web. */
    public const string CSP = "object-src 'none'; base-uri 'self'; frame-ancestors 'self'";

    private const array CABECERAS = [
        'Content-Security-Policy' => self::CSP,
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    ];

    public function __invoke(ResponseEvent $evento): void
    {
        if (!$evento->isMainRequest()) {
            return;
        }
        $cabeceras = $evento->getResponse()->headers;
        foreach (self::CABECERAS as $nombre => $valor) {
            if (!$cabeceras->has($nombre)) {
                $cabeceras->set($nombre, $valor);
            }
        }
        // Una página con su propia CSP (incrustar, LTI) decide dónde se muestra: no se le agrega X-Frame-Options.
        if ($cabeceras->get('Content-Security-Policy') === self::CSP && !$cabeceras->has('X-Frame-Options')) {
            $cabeceras->set('X-Frame-Options', 'SAMEORIGIN');
        }
    }
}
