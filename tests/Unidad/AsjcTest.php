<?php

declare(strict_types=1);

namespace App\Tests\Unidad;

use App\Clasificacion\Asjc;
use App\Entity\Materia;
use PHPUnit\Framework\TestCase;

final class AsjcTest extends TestCase
{
    public function testListaCompletaConNombresEnAmbosIdiomas(): void
    {
        self::assertCount(334, Asjc::CATEGORIAS);
        self::assertSame('Derecho', Asjc::nombre(3308));
        self::assertSame('Law', Asjc::nombre(3308, 'en'));
        self::assertCount(27, Asjc::porCampo());
    }

    public function testCampoYGranArea(): void
    {
        self::assertSame(3300, Asjc::campo(3308));
        self::assertSame('sociales', Asjc::area(3308));
        self::assertSame('salud', Asjc::area(2700));
        self::assertSame('vida', Asjc::area(1104));
        self::assertSame('fisicas', Asjc::area(2205));
        self::assertSame('multidisciplinaria', Asjc::area(1000));
        self::assertNull(Asjc::area(9999));
    }

    public function testLaMateriaGuardaCodigosValidosOrdenadosYSinRepetir(): void
    {
        $materia = (new Materia())->setAsjc(['3308', 2700, 3308, 12, 'x']);
        self::assertSame([2700, 3308], $materia->getAsjc());
        self::assertSame(['salud', 'sociales'], $materia->getAreas());
    }
}
