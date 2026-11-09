<?php

declare(strict_types=1);

namespace App\Tests\Unidad;

use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use PHPUnit\Framework\TestCase;

final class RecursoTest extends TestCase
{
    public function testLetraDelIndice(): void
    {
        self::assertSame('E', (new Recurso())->setTitulo('Ética y Sociedad')->getLetra());
        self::assertSame('N', (new Recurso())->setTitulo('ñandú')->getLetra());
        self::assertSame('#', (new Recurso())->setTitulo('3D Models')->getLetra());
        self::assertSame('S', (new Recurso())->setTitulo('  scopus')->getLetra());
    }

    public function testRecursoEnPruebaSoloSeMuestraMientrasEsteVigente(): void
    {
        $hoy = new \DateTimeImmutable('2026-11-09');
        $recurso = (new Recurso())->setAcceso(TipoAcceso::Prueba)->setPruebaHasta(new \DateTimeImmutable('2026-11-09'));

        self::assertTrue($recurso->estaDisponible($hoy));
        self::assertFalse($recurso->estaDisponible($hoy->modify('+1 day')));
        self::assertFalse($recurso->setVisible(false)->estaDisponible($hoy));
    }
}
