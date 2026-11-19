<?php

declare(strict_types=1);

namespace App\Tests\Recursos;

use App\Entity\Autor;
use App\Entity\Editorial;
use App\Entity\Recurso;
use App\Enum\FormatoRecurso;
use App\Enum\Rol;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Servicio\Importacion\ImportadorRecursos;
use App\Servicio\Importacion\LectorBibliografia;
use App\Servicio\Importacion\PlantillaImportacion;
use App\Tests\CreaDatosTrait;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExportarRecursosTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
    }

    public function testExportaAExcelConLaPlantillaYSePuedeVolverAImportar(): void
    {
        $this->crearRecursos();
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));

        $crawler = $this->cliente->request('GET', '/es/panel/recursos?tipo=libros');
        $enlace = $crawler->filter('.exportar-recursos__enlaces a')->first()->attr('href');
        self::assertSame('/es/panel/recursos/exportar.xlsx?tipo=libros', $enlace);

        $this->cliente->request('GET', '/es/panel/recursos/exportar.xlsx');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $archivo = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($archivo, $this->cliente->getInternalResponse()->getContent());

        try {
            $hoja = IOFactory::load($archivo)->getActiveSheet()->toArray();
            self::assertSame(PlantillaImportacion::ENCABEZADOS, $hoja[0]);
            self::assertCount(3, $hoja); // encabezado + 2 recursos, por título
            $libro = array_combine(PlantillaImportacion::ENCABEZADOS, $hoja[1]);
            self::assertSame('=Introducción a la programación', $libro['Título']);
            self::assertSame('Libros', $libro['Tipo']);
            self::assertSame('Impreso y electrónico', $libro['Formato']);
            self::assertSame('Pérez, Ana; Soto, Luis', $libro['Autores']);
            self::assertSame('9789561123458', $libro['ISBN']);
            self::assertSame('Bioquímica, genética y biología molecular; Ingeniería', $libro['Materias']);
            self::assertSame('Sí', $libro['Requiere proxy']);
            self::assertNull($libro['ISSN']);

            // Al reimportar el mismo archivo se reconocen los recursos y las materias (incluso con coma en el nombre).
            $filas = static::getContainer()->get(ImportadorRecursos::class)->leer($archivo);
            self::assertSame(['actualizar', 'actualizar'], array_map(fn ($f) => $f->accion(), $filas));
            self::assertSame(['Bioquímica, genética y biología molecular', 'Ingeniería'], $filas[0]->materias);
            self::assertSame(FormatoRecurso::Ambos, $filas[0]->datos['formato']);
            self::assertSame(TipoRecurso::Articulo, $filas[1]->datos['tipo']);
            self::assertSame('12-15', $filas[1]->datos['paginas']);
            self::assertSame(TipoAcceso::Abierto, $filas[1]->datos['acceso']);
        } finally {
            @unlink($archivo);
        }
    }

    public function testExportaARisRespetandoLosFiltros(): void
    {
        $this->crearRecursos();
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));

        $this->cliente->request('GET', '/en/panel/recursos/exportar.ris?tipo=articulo');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/x-research-info-systems; charset=UTF-8');
        $ris = $this->cliente->getResponse()->getContent();

        self::assertStringContainsString("TY  - JOUR\r\nTI  - Un artículo\r\nAU  - Rojas, Marta\r\nT2  - Revista Chilena\r\nPY  - 2020\r\n", $ris);
        self::assertStringContainsString("SP  - 12\r\nEP  - 15\r\n", $ris);
        self::assertStringContainsString("DO  - 10.1000/xyz\r\n", $ris);
        self::assertStringContainsString("AB  - English abstract on one line\r\n", $ris);
        self::assertStringContainsString("KW  - Engineering\r\n", $ris);
        self::assertStringNotContainsString('programación', $ris);

        // El lector RIS de la importación entiende lo exportado.
        $registros = (new LectorBibliografia())->leerRis($ris);
        self::assertCount(1, $registros);
        self::assertSame('articulo', $registros[0]['tipo']);
        self::assertSame('Rojas, Marta', $registros[0]['autores']);
        self::assertSame('12-15', $registros[0]['paginas']);
    }

    public function testLosColaboradoresNoExportan(): void
    {
        $this->cliente->loginUser(self::crearUsuario('colaborador@ucn.cl', Rol::Colaborador));
        $this->cliente->request('GET', '/es/panel/recursos/exportar.ris');

        self::assertResponseStatusCodeSame(403);
    }

    private function crearRecursos(): void
    {
        $bioquimica = self::crearMateria('Bioquímica, genética y biología molecular', 'bioquimica');
        $ingenieria = self::crearMateria('Ingeniería', 'ingenieria')->setNombreEn('Engineering');
        $em = self::em();
        $autores = [];
        foreach ([['Ana', 'Pérez'], ['Luis', 'Soto'], ['Marta', 'Rojas']] as [$nombres, $apellidos]) {
            $em->persist($autores[] = (new Autor())->setNombres($nombres)->setApellidos($apellidos));
        }
        $em->persist($editorial = (new Editorial())->setNombre('Editorial Universitaria'));

        $em->persist((new Recurso())->setTitulo('=Introducción a la programación')->setUrl('https://ebooks.org/1')
            ->setTipo(TipoRecurso::Libros)->setFormato(FormatoRecurso::Ambos)->setRequiereProxy(true)
            ->setAutores([$autores[0], $autores[1]])->setEditorial($editorial)->setIsbn('9789561123458')->setAnio(2021)
            ->addMateria($ingenieria)->addMateria($bioquimica));
        $em->persist((new Recurso())->setTitulo('Un artículo')->setUrl('https://doi.org/10.1000/xyz')
            ->setTipo(TipoRecurso::Articulo)->setAcceso(TipoAcceso::Abierto)->setRequiereProxy(false)
            ->setAutores([$autores[2]])->setFuente('Revista Chilena')->setAnio(2020)->setPaginas('12-15')->setDoi('10.1000/xyz')
            ->setDescripcion('Resumen')->setDescripcionEn("English abstract\non one line")->addMateria($ingenieria));
        $em->flush();
    }
}
