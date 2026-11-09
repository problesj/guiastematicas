<?php

declare(strict_types=1);

namespace App\Tests\Unidad;

use App\Entity\Recurso;
use App\Servicio\ProxyAccesum;
use PHPUnit\Framework\TestCase;

final class ProxyAccesumTest extends TestCase
{
    public function testPrefijo(): void
    {
        $proxy = new ProxyAccesum('https://accesum.ucn.cl/login?url=');

        self::assertSame('https://accesum.ucn.cl/login?url=https://www.scopus.com', $proxy->aplicar('https://www.scopus.com'));
        self::assertSame('https://www.scopus.com', $proxy->quitar('https://accesum.ucn.cl/login?url=https://www.scopus.com'));
        self::assertNull($proxy->quitar('https://www.scopus.com'));
        // No se aplica dos veces.
        self::assertSame('https://accesum.ucn.cl/login?url=https://x.org', $proxy->aplicar('https://accesum.ucn.cl/login?url=https://x.org'));
    }

    public function testPlantillaConUrlCodificada(): void
    {
        $proxy = new ProxyAccesum('https://proxy.ucn.cl/login?qurl={url}');

        $enlace = $proxy->aplicar('https://x.org/buscar?q=a&b=1');
        self::assertSame('https://proxy.ucn.cl/login?qurl=https%3A%2F%2Fx.org%2Fbuscar%3Fq%3Da%26b%3D1', $enlace);
        self::assertSame('https://x.org/buscar?q=a&b=1', $proxy->quitar($enlace));
    }

    public function testSinProxyConfiguradoUsaLaUrlOriginal(): void
    {
        $proxy = new ProxyAccesum('');
        $recurso = (new Recurso())->setUrl('https://www.scopus.com')->setRequiereProxy(true);

        self::assertFalse($proxy->estaConfigurado());
        self::assertSame('https://www.scopus.com', $proxy->enlace($recurso));
    }

    public function testRecursoSinProxy(): void
    {
        $proxy = new ProxyAccesum('https://accesum.ucn.cl/login?url=');
        $recurso = (new Recurso())->setUrl('https://www.scielo.cl')->setRequiereProxy(false);

        self::assertSame('https://www.scielo.cl', $proxy->enlace($recurso));
    }
}
