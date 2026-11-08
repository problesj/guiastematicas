<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SaludControllerTest extends WebTestCase
{
    public function testDevuelveElEstadoEnJson(): void
    {
        $cliente = static::createClient();
        $cliente->request('GET', '/salud');

        // 200 con base de datos disponible (CI con MySQL), 503 sin ella.
        self::assertContains($cliente->getResponse()->getStatusCode(), [200, 503]);
        $datos = json_decode((string) $cliente->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('estado', $datos);
        self::assertArrayHasKey('base_de_datos', $datos);
    }
}
