<?php

declare(strict_types=1);

namespace App\Tests\Unidad;

use App\Servicio\Importacion\LectorBibliografia;
use PHPUnit\Framework\TestCase;

final class LectorBibliografiaTest extends TestCase
{
    public function testLeeBibtex(): void
    {
        $bib = <<<'BIB'
            @comment{Exportado desde Zotero}
            @string{rmc = "Revista M{\'e}dica de Chile"}
            @Article{perez2021,
              author  = {P{\'e}rez, Ana and Mu\~{n}oz, Luis},
              title   = {{Resistencia} antimicrobiana en {Antofagasta}},
              journal = rmc # " (Santiago)",
              year    = 2021,
              volume  = {149},
              number  = "3",
              pages   = {315--322},
              doi     = {https://doi.org/10.4067/S0034-98872021000300315},
              abstract = {Estudio de \textit{cohorte} con 120 pacientes \& controles.},
            }

            @book{soto2019, title={Introducci\'on a la programaci\'on}, author={Soto, Luis}, publisher={Editorial Universitaria}, year={2019}, isbn={978-956-11-2345-8}}
            @misc{video1, title = {Cómo buscar en Scopus}, url = {https://www.youtube.com/watch?v=dQw4w9WgXcQ}, year = {2023}}
            BIB;

        $registros = (new LectorBibliografia())->leerBibtex($bib);

        self::assertCount(3, $registros);
        [$articulo, $libro, $video] = $registros;
        self::assertSame('articulo', $articulo['tipo']);
        self::assertSame('Resistencia antimicrobiana en Antofagasta', $articulo['titulo']);
        self::assertSame('Pérez, Ana; Muñoz, Luis', $articulo['autores']);
        self::assertSame('Revista Médica de Chile (Santiago)', $articulo['fuente']);
        self::assertSame('2021', $articulo['anio']);
        self::assertSame('149', $articulo['volumen']);
        self::assertSame('3', $articulo['fasciculo']);
        self::assertSame('315–322', $articulo['paginas']);
        self::assertSame('https://doi.org/10.4067/S0034-98872021000300315', $articulo['doi']);
        self::assertStringContainsString('120 pacientes & controles', $articulo['descripcion']);

        self::assertSame('libros', $libro['tipo']);
        self::assertSame('Introducción a la programación', $libro['titulo']);
        self::assertSame('Editorial Universitaria', $libro['editorial']);
        self::assertSame('978-956-11-2345-8', $libro['isbn']);

        self::assertSame('multimedia', $video['tipo']);
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $video['url']);
    }

    public function testLeeRis(): void
    {
        $ris = "TY  - JOUR\r\nAU  - Pérez, Ana\r\nAU  - Soto, Luis\r\nTI  - Resistencia antimicrobiana\r\nT2  - Revista Médica de Chile\r\nPY  - 2021/03/01\r\nVL  - 149\r\nIS  - 3\r\nSP  - 315\r\nEP  - 322\r\nSN  - 0034-9887\r\nDO  - 10.4067/S0034-98872021000300315\r\nAB  - Estudio de cohorte\r\n  con 120 pacientes.\r\nER  - \r\n\r\nTY  - BOOK\r\nTI  - Introducción a la programación\r\nAU  - Soto, Luis\r\nPB  - Editorial Universitaria\r\nPY  - 2019\r\nSN  - 978-956-11-2345-8\r\nUR  - https://ebooks.org/1\r\nER  - \r\n";

        $registros = (new LectorBibliografia())->leerRis($ris);

        self::assertCount(2, $registros);
        [$articulo, $libro] = $registros;
        self::assertSame('articulo', $articulo['tipo']);
        self::assertSame('Pérez, Ana; Soto, Luis', $articulo['autores']);
        self::assertSame('Revista Médica de Chile', $articulo['fuente']);
        self::assertSame('2021', $articulo['anio']);
        self::assertSame('315-322', $articulo['paginas']);
        self::assertSame('0034-9887', $articulo['issn']);
        self::assertSame('Estudio de cohorte con 120 pacientes.', $articulo['descripcion']);
        self::assertSame('libros', $libro['tipo']);
        self::assertSame('978-956-11-2345-8', $libro['isbn']);
        self::assertArrayNotHasKey('issn', $libro);
    }
}
