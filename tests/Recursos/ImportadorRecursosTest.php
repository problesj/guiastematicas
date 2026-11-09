<?php

declare(strict_types=1);

namespace App\Tests\Recursos;

use App\Entity\Materia;
use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Servicio\Importacion\ArchivoNoValidoException;
use App\Servicio\Importacion\ImportadorRecursos;
use App\Servicio\ProxyAccesum;
use App\Tests\CreaDatosTrait;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportadorRecursosTest extends KernelTestCase
{
    use CreaDatosTrait;

    private string $archivo;

    protected function setUp(): void
    {
        self::bootKernel();
        static::getContainer()->set(ProxyAccesum::class, new ProxyAccesum('https://accesum.ucn.cl/login?url='));
        $this->archivo = sys_get_temp_dir().'/importacion-'.bin2hex(random_bytes(4)).'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->archivo);
        parent::tearDown();
    }

    /** @param list<list<mixed>> $filas */
    private function excel(array $filas): string
    {
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->fromArray($filas);
        (new Xlsx($libro))->save($this->archivo);

        return $this->archivo;
    }

    private function importador(): ImportadorRecursos
    {
        return static::getContainer()->get(ImportadorRecursos::class);
    }

    public function testInterpretaEncabezadosYValores(): void
    {
        self::crearMateria('Medicina', 'medicina');
        $filas = $this->importador()->leer($this->excel([
            ['Listado de bases de datos Bibliotecas UCN'],
            ['NOMBRE', 'Enlace', 'Tipo de acceso', 'Formato', 'Áreas', 'Plataforma'],
            ['Scopus', 'https://accesum.ucn.cl/login?url=https://www.scopus.com', 'Suscripción', 'Base de datos', 'Medicina; Ingeniería Civil', 'Elsevier'],
            ['SciELO', 'www.scielo.cl', 'Acceso abierto', 'Revistas', '', ''],
            ['', '', '', '', '', ''],
            ['Sin enlace', '', '', '', '', ''],
            ['Scopus repetido', 'https://www.scopus.com', '', '', '', ''],
            ['Raro', 'https://raro.org', '', 'Cosa extraña', '', ''],
        ]));

        self::assertCount(5, $filas);
        [$scopus, $scielo, $sinEnlace, $repetido, $raro] = $filas;

        self::assertSame('crear', $scopus->accion());
        self::assertSame('https://www.scopus.com', $scopus->datos['url']);
        self::assertTrue($scopus->datos['requiere_proxy']);
        self::assertContains('importacion.aviso_proxy_quitado', $scopus->avisos);
        self::assertSame(['Medicina', 'Ingeniería Civil'], $scopus->materias);
        self::assertSame('Elsevier', $scopus->datos['proveedor']);

        self::assertSame('https://www.scielo.cl', $scielo->datos['url']);
        self::assertSame(TipoAcceso::Abierto, $scielo->datos['acceso']);
        self::assertSame(TipoRecurso::Revistas, $scielo->datos['tipo']);
        self::assertFalse($scielo->datos['requiere_proxy']);

        self::assertSame('error', $sinEnlace->accion());
        self::assertSame(['importacion.error_repetido|3'], $repetido->errores);
        self::assertSame(TipoRecurso::BaseDatos, $raro->datos['tipo']);
        self::assertNotEmpty($raro->avisos);
    }

    public function testAplicaCreandoMateriasYActualizaExistentes(): void
    {
        $medicina = self::crearMateria('Medicina', 'medicina');
        $existente = (new Recurso())->setTitulo('Scopus')->setUrl('https://www.scopus.com')->setDescripcion('Antigua');
        self::em()->persist($existente);
        self::em()->flush();

        $importador = $this->importador();
        $filas = $importador->leer($this->excel([
            ['Título', 'URL', 'Descripción', 'Materias'],
            ['Scopus', 'https://www.scopus.com', 'Nueva descripción', 'medicina'],
            ['PubMed', 'https://pubmed.ncbi.nlm.nih.gov', 'Biomedicina', 'Medicina; Enfermería; enfermeria'],
            ['', 'https://sin-titulo.org', '', ''],
        ]));
        self::assertSame(['actualizar', 'crear', 'error'], array_map(fn ($f) => $f->accion(), $filas));

        $resultado = $importador->aplicar($filas);

        self::assertSame(['creados' => 1, 'actualizados' => 1, 'omitidos' => 1, 'materias_nuevas' => 1], $resultado);
        self::em()->clear();
        $scopus = self::em()->getRepository(Recurso::class)->findOneBy(['url' => 'https://www.scopus.com']);
        self::assertSame('Nueva descripción', $scopus->getDescripcion());
        self::assertSame($medicina->getId(), $scopus->getMaterias()->first()->getId());
        $pubmed = self::em()->getRepository(Recurso::class)->findOneBy(['titulo' => 'PubMed']);
        self::assertCount(2, $pubmed->getMaterias());
        self::assertNotNull(self::em()->getRepository(Materia::class)->findOneBy(['slug' => 'enfermeria']));
    }

    public function testUsaElHipervinculoDelTituloSiNoHayColumnaUrl(): void
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->fromArray([['Base de datos', 'Descripción'], ['Web of Science', 'Citas']]);
        $hoja->getCell('A2')->getHyperlink()->setUrl('https://www.webofscience.com');
        (new Xlsx($libro))->save($this->archivo);

        $filas = $this->importador()->leer($this->archivo);

        self::assertSame('https://www.webofscience.com', $filas[0]->datos['url']);
        self::assertTrue($filas[0]->esValida());
    }

    public function testLeeCsv(): void
    {
        $this->archivo = sys_get_temp_dir().'/importacion-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($this->archivo, "title,url,access\nDOAJ,https://doaj.org,open\n");

        $filas = $this->importador()->leer($this->archivo);

        self::assertSame('DOAJ', $filas[0]->datos['titulo']);
        self::assertSame(TipoAcceso::Abierto, $filas[0]->datos['acceso']);
    }

    public function testRechazaArchivoSinColumnasMinimas(): void
    {
        $this->expectException(ArchivoNoValidoException::class);

        $this->importador()->leer($this->excel([['Columna A', 'Columna B'], ['x', 'y']]));
    }
}
