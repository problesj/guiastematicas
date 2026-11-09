<?php

declare(strict_types=1);

namespace App\Tests\Unidad;

use App\Entity\Autor;
use App\Entity\Recurso;
use PHPUnit\Framework\TestCase;

final class AutorTest extends TestCase
{
    public function testOrcid(): void
    {
        self::assertTrue(Autor::orcidValido('0000-0002-1825-0097'));
        self::assertTrue(Autor::orcidValido('0000-0002-1694-233X'));
        self::assertFalse(Autor::orcidValido('0000-0002-1825-0098'));
        self::assertFalse(Autor::orcidValido('0000-0002-1825'));

        self::assertSame('0000-0002-1825-0097', Autor::normalizarOrcid('https://orcid.org/0000-0002-1825-0097'));
        self::assertSame('0000-0002-1694-233X', Autor::normalizarOrcid(' 0000 0002 1694 233x '));
        self::assertNull(Autor::normalizarOrcid('  '));
    }

    public function testNombreDesdeTexto(): void
    {
        $ana = Autor::desdeTexto('  Pérez   Rojas ,  Ana María ');
        self::assertSame('Pérez Rojas', $ana->getApellidos());
        self::assertSame('Ana María', $ana->getNombres());
        self::assertSame('Pérez Rojas, Ana María', $ana->getNombreCompleto());

        // Sin coma queda como autor institucional.
        $oms = Autor::desdeTexto('Organización Mundial de la Salud');
        self::assertSame('Organización Mundial de la Salud', $oms->getNombreCompleto());
        self::assertSame('', $oms->getNombres());
    }

    public function testElRecursoConservaElOrdenYNoRepiteAutores(): void
    {
        $ana = Autor::desdeTexto('Pérez, Ana');
        $luis = Autor::desdeTexto('Soto, Luis');
        $recurso = (new Recurso())->setAutores([$ana, $luis, $ana]);
        self::assertSame('Pérez, Ana; Soto, Luis', $recurso->getAutoresTexto());

        $recurso->setAutores([$luis, $ana]);
        self::assertSame('Soto, Luis; Pérez, Ana', $recurso->getAutoresTexto());

        $recurso->setAutores([$luis]);
        self::assertSame([$luis], $recurso->getAutores());
        self::assertNull((new Recurso())->getAutoresTexto());
    }
}
