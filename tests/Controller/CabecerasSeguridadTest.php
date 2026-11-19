<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\EventSubscriber\CabecerasSeguridadSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CabecerasSeguridadTest extends WebTestCase
{
    public function testTodasLasRespuestasLlevanCabecerasDeSeguridad(): void
    {
        $cliente = static::createClient();
        $cliente->request('GET', '/es/acceso');

        $cabeceras = $cliente->getResponse()->headers;
        self::assertSame(CabecerasSeguridadSubscriber::CSP, $cabeceras->get('Content-Security-Policy'));
        self::assertSame('SAMEORIGIN', $cabeceras->get('X-Frame-Options'));
        self::assertSame('nosniff', $cabeceras->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $cabeceras->get('Referrer-Policy'));
        self::assertStringContainsString('camera=()', (string) $cabeceras->get('Permissions-Policy'));
    }
}
