<?php

declare(strict_types=1);

namespace App\Tests\Unidad;

use App\Paginacion\Pagina;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PaginaTest extends TestCase
{
    public function testLeeParametrosValidos(): void
    {
        self::assertSame([3, 50], Pagina::leerParametros(new Request(['pagina' => '3', 'por_pagina' => '50'])));
        self::assertSame([1, 25], Pagina::leerParametros(new Request(['pagina' => '-4', 'por_pagina' => '1000'])));
        self::assertSame([1, 25], Pagina::leerParametros(new Request()));
    }

    public function testEnlacesConSaltos(): void
    {
        $pagina = (new \ReflectionClass(Pagina::class))->newInstanceWithoutConstructor();
        $constructor = new \ReflectionMethod(Pagina::class, '__construct');
        $constructor->invoke($pagina, [], 200, 10, 10);

        self::assertSame(20, $pagina->getPaginas());
        self::assertSame([1, null, 8, 9, 10, 11, 12, null, 20], $pagina->getEnlaces());
        self::assertSame(91, $pagina->getDesde());
        self::assertSame(100, $pagina->getHasta());
    }
}
