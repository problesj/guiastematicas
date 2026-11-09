<?php

declare(strict_types=1);

namespace App\Tests\Recursos;

use App\Entity\Autor;
use App\Entity\Editorial;
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
            ['JSTOR', 'https://itmsp.museknowledge.com/UCN?url=https://www.jstor.org', '', '', '', ''],
            ['Wiley', 'https://itmsp.museknowledge.com/UCN?qurl=https%3A%2F%2Fonlinelibrary.wiley.com', 'Acceso abierto', '', '', ''],
        ]));

        self::assertCount(7, $filas);
        [$scopus, $scielo, $sinEnlace, $repetido, $raro, $jstor, $wiley] = $filas;
        self::assertSame('https://www.jstor.org', $jstor->datos['url']);
        self::assertTrue($jstor->datos['requiere_proxy']);
        self::assertSame('https://onlinelibrary.wiley.com', $wiley->datos['url']);
        // Venía con proxy: se mantiene "requiere proxy" aunque diga acceso abierto.
        self::assertTrue($wiley->datos['requiere_proxy']);

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

    public function testImportaDatosBibliograficosSegunElTipo(): void
    {
        $importador = $this->importador();
        $filas = $importador->leer($this->excel([
            ['Título', 'URL', 'Tipo', 'Autor', 'Editorial', 'Año', 'ISBN', 'ISBN electrónico', 'ISSN', 'e-ISSN', 'Fecha de disponibilidad', 'Código de la norma', 'Organismo emisor'],
            ['Introducción a la programación', 'https://ebooks.org/1', 'Libro electrónico', 'Pérez, Ana; Soto, Luis', 'Editorial Universitaria', '2021', '978-956-11-2345-8', '978 956 11 2345 0', '', '', '', '', ''],
            ['Revista Médica de Chile', 'https://revista.cl', 'Revista', '', 'Sociedad Médica', '', '9780131103627', '', '00349887', '0717-6163', '01-01-2000', '', ''],
            ['Hormigón', 'https://inn.cl', 'Norma', '', '', '2016', '', '', '', '', '', 'NCh 170', 'INN'],
            ['Scopus', 'https://www.scopus.com', 'Base de datos', '', 'Elsevier', '', '', '', '', '', '01-01-1996', '', ''],
        ]));

        [$libro, $revista, $norma, $base] = $filas;
        self::assertSame(2021, $libro->datos['anio']);
        self::assertSame('978-956-11-2345-8', $libro->datos['isbn']);
        // e-ISBN con dígito verificador incorrecto: se descarta con aviso y la fila se importa igual.
        self::assertNull($libro->datos['eisbn']);
        self::assertContains('importacion.aviso_isbn|978 956 11 2345 0', $libro->avisos);
        self::assertTrue($libro->esValida());

        $importador->aplicar($filas);
        self::em()->clear();
        $repo = self::em()->getRepository(Recurso::class);

        $libro = $repo->findOneBy(['titulo' => 'Introducción a la programación']);
        self::assertSame('Pérez, Ana; Soto, Luis', $libro->getAutoresTexto());
        self::assertSame('Editorial Universitaria', $libro->getEditorial()?->getNombre());
        self::assertSame(2021, $libro->getAnio());

        $revista = $repo->findOneBy(['titulo' => 'Revista Médica de Chile']);
        self::assertSame('0034-9887', $revista->getIssn());
        self::assertSame('0717-6163', $revista->getEissn());
        self::assertSame('2000-01-01', $revista->getDisponibleDesde()?->format('Y-m-d'));
        // Un ISBN no corresponde a una revista: no se guarda.
        self::assertNull($revista->getIsbn());

        $norma = $repo->findOneBy(['titulo' => 'Hormigón']);
        self::assertSame(['codigo_norma' => 'NCh 170', 'organismo' => 'INN', 'anio' => '2016'], $norma->getDatosBibliograficos());

        // En una base de datos la columna "Editorial" pasa a ser el proveedor.
        $base = $repo->findOneBy(['titulo' => 'Scopus']);
        self::assertSame('Elsevier', $base->getProveedor());
        self::assertNull($base->getEditorial());
        self::assertSame('1996-01-01', $base->getDisponibleDesde()?->format('Y-m-d'));
    }

    public function testLaImportacionReutilizaAutoresYEditoriales(): void
    {
        $existente = (new Autor())->setApellidos('Soto')->setNombres('Luis');
        self::em()->persist($existente);
        self::em()->flush();

        $importador = $this->importador();
        $filas = $importador->leer($this->excel([
            ['Título', 'URL', 'Tipo', 'Autores', 'Editorial'],
            ['Libro uno', 'https://ebooks.org/1', 'Libro', 'Pérez, Ana; SOTO, Luis', 'Editorial Universitaria'],
            ['Libro dos', 'https://ebooks.org/2', 'Libro', 'pérez, ana; Organización Mundial de la Salud', 'Editorial Universitaria'],
        ]));
        $importador->aplicar($filas);
        self::em()->clear();

        $repo = self::em()->getRepository(Recurso::class);
        self::assertSame('Pérez, Ana; Soto, Luis', $repo->findOneBy(['titulo' => 'Libro uno'])->getAutoresTexto());
        self::assertSame('Pérez, Ana; Organización Mundial de la Salud', $repo->findOneBy(['titulo' => 'Libro dos'])->getAutoresTexto());
        self::assertSame($existente->getId(), $repo->findOneBy(['titulo' => 'Libro uno'])->getAutores()[1]->getId());
        self::assertCount(3, self::em()->getRepository(Autor::class)->findAll());
        self::assertCount(1, self::em()->getRepository(Editorial::class)->findAll());
    }

    public function testImportaBibtexConDoiComoEnlace(): void
    {
        $ruta = sys_get_temp_dir().'/referencias-'.bin2hex(random_bytes(4)).'.bib';
        file_put_contents($ruta, <<<'BIB'
            @article{a1, title={Resistencia antimicrobiana}, author={P{\'e}rez, Ana}, journal={Revista M{\'e}dica de Chile},
              year={2021}, volume={149}, number={3}, pages={315--322}, doi={10.4067/S0034-98872021000300315}}
            @article{a2, title={Sin enlace}, author={Soto, Luis}, journal={Otra}, year={2020}}
            @misc{v1, title={Cómo buscar en Scopus}, url={https://youtu.be/dQw4w9WgXcQ}}
            BIB);

        try {
            $importador = $this->importador();
            $filas = $importador->leer($ruta);
            self::assertSame(['crear', 'error', 'crear'], array_map(fn ($f) => $f->accion(), $filas));
            self::assertContains('importacion.error_sin_url', $filas[1]->errores);

            $importador->aplicar($filas);
        } finally {
            @unlink($ruta);
        }

        self::em()->clear();
        $articulo = self::em()->getRepository(Recurso::class)->findOneBy(['titulo' => 'Resistencia antimicrobiana']);
        self::assertSame(TipoRecurso::Articulo, $articulo->getTipo());
        self::assertSame('https://doi.org/10.4067/S0034-98872021000300315', $articulo->getUrl());
        self::assertSame('Revista Médica de Chile', $articulo->getFuente());
        self::assertSame(['autores' => 'Pérez, Ana', 'fuente' => 'Revista Médica de Chile', 'anio' => '2021', 'volumen' => '149', 'fasciculo' => '3', 'paginas' => '315-322', 'doi' => '10.4067/S0034-98872021000300315'], $articulo->getDatosBibliograficos());
        $video = self::em()->getRepository(Recurso::class)->findOneBy(['titulo' => 'Cómo buscar en Scopus']);
        self::assertSame(TipoRecurso::Multimedia, $video->getTipo());
    }

    public function testVariasUrlYSeleccionDeLaVistaPrevia(): void
    {
        $medicina = self::crearMateria('Medicina', 'medicina');
        $ruta = sys_get_temp_dir().'/referencias-'.bin2hex(random_bytes(4)).'.bib';
        file_put_contents($ruta, <<<'BIB'
            @article{a1, title={Marketing digital}, journal={Texto Livre}, doi={10.35699/1983-3652.2021.26251},
              url={http://dx.doi.org/10.35699/1983-3652.2021.26251 https://periodicos.ufmg.br/index.php/textolivre/article/view/26251}}
            @article{a2, title={Otro artículo}, url={https://revista.cl/2 nota-sin-url}, keywords={x}}
            @article{a3, title={Descartado}, url={https://revista.cl/3}}
            BIB);

        try {
            $importador = $this->importador();
            $filas = $importador->leer($ruta);
            [$marketing, $otro] = $filas;
            // El enlace http://dx.doi.org del campo url y el del DOI son el mismo: queda una sola opción.
            self::assertSame(['https://doi.org/10.35699/1983-3652.2021.26251', 'https://periodicos.ufmg.br/index.php/textolivre/article/view/26251'], $marketing->urls);
            self::assertTrue($marketing->esValida());
            self::assertSame(['https://revista.cl/2', 'https://nota-sin-url'], $otro->urls);

            $importador->aplicarSeleccion($filas, [
                1 => ['importar' => true, 'url' => 'https://periodicos.ufmg.br/index.php/textolivre/article/view/26251', 'proxy' => false, 'materias' => [$medicina->getId()]],
                2 => ['importar' => true, 'url' => 'https://no-estaba-en-el-archivo.org', 'materias' => ['Inventada']],
                3 => ['importar' => false],
            ]);
            $resultado = $importador->aplicar($filas);
        } finally {
            @unlink($ruta);
        }

        self::assertSame(2, $resultado['creados']);
        self::assertSame(1, $resultado['omitidos']);
        self::em()->clear();
        $repo = self::em()->getRepository(Recurso::class);
        $marketing = $repo->findOneBy(['titulo' => 'Marketing digital']);
        self::assertSame('https://periodicos.ufmg.br/index.php/textolivre/article/view/26251', $marketing->getUrl());
        self::assertFalse($marketing->isRequiereProxy());
        self::assertSame(['Medicina'], $marketing->getMaterias()->map(fn ($m) => $m->getNombre())->getValues());
        // Una URL o materia que no venía en el archivo se ignora.
        $otro = $repo->findOneBy(['titulo' => 'Otro artículo']);
        self::assertSame('https://revista.cl/2', $otro->getUrl());
        self::assertCount(0, $otro->getMaterias());
        self::assertNull($repo->findOneBy(['titulo' => 'Descartado']));
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
