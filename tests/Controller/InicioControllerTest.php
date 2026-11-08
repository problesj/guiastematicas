<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InicioControllerTest extends WebTestCase
{
    public function testLaRaizRedirigeAlIdiomaPreferido(): void
    {
        $cliente = static::createClient();
        $cliente->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9']);

        self::assertResponseRedirects('/en');
    }

    public function testLaRaizUsaEspanolSiElIdiomaNoEstaDisponible(): void
    {
        $cliente = static::createClient();
        $cliente->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);

        self::assertResponseRedirects('/es');
    }

    public function testPortadaEnEspanol(): void
    {
        $cliente = static::createClient();
        $cliente->request('GET', '/es');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Guías temáticas');
        self::assertSelectorExists('html[lang="es"]');
    }

    public function testPortadaEnIngles(): void
    {
        $cliente = static::createClient();
        $cliente->request('GET', '/en');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Research guides');
    }

    public function testIdiomaNoSoportadoDevuelve404(): void
    {
        $cliente = static::createClient();
        $cliente->request('GET', '/fr');

        self::assertResponseStatusCodeSame(404);
    }
}
