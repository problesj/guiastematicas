<?php

declare(strict_types=1);

namespace App\Tests\Recursos;

use App\Entity\Recurso;
use App\Enum\Rol;
use App\Tests\CreaDatosTrait;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PanelRecursosTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testLosColaboradoresNoAdministranRecursos(): void
    {
        $this->cliente->loginUser(self::crearUsuario('colaborador@ucn.cl', Rol::Colaborador));
        $this->cliente->request('GET', '/es/panel/recursos');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnEditorCreaUnRecurso(): void
    {
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $this->cliente->request('GET', '/es/panel/recursos/nuevo');
        $this->cliente->submitForm('Guardar', [
            'recurso[titulo]' => 'JSTOR',
            'recurso[url]' => 'https://www.jstor.org',
            'recurso[tipo]' => 'revistas',
            'recurso[acceso]' => 'suscripcion',
            'recurso[requiereProxy]' => true,
            'recurso[visible]' => true,
        ]);

        self::assertResponseRedirects('/es/panel/recursos');
        $jstor = self::em()->getRepository(Recurso::class)->findOneBy(['titulo' => 'JSTOR']);
        self::assertSame('J', $jstor->getLetra());
        self::assertTrue($jstor->isRequiereProxy());
    }

    public function testLosDatosBibliograficosSeValidanYDependenDelTipo(): void
    {
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $crawler = $this->cliente->request('GET', '/es/panel/recursos/nuevo');
        // Cada dato indica para qué tipos se muestra.
        self::assertSame('libros', $crawler->filter('#recurso_isbn')->ancestors()->filter('[data-campos-tipo-target="campo"]')->attr('data-tipos'));

        $datos = [
            'recurso[titulo]' => 'Introducción a la programación',
            'recurso[url]' => 'https://ebooks.org/1',
            'recurso[tipo]' => 'libros',
            'recurso[acceso]' => 'suscripcion',
            'recurso[isbn]' => '978-956-11-2345-0',
            'recurso[anio]' => '2021',
        ];
        $this->cliente->submitForm('Guardar', $datos);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.campo__errores li', 'El ISBN no es válido');

        $this->cliente->submitForm('Guardar', ['recurso[isbn]' => '978-956-11-2345-8'] + $datos);
        self::assertResponseRedirects('/es/panel/recursos');
        $libro = self::em()->getRepository(Recurso::class)->findOneBy(['titulo' => 'Introducción a la programación']);
        self::assertSame('978-956-11-2345-8', $libro->getIsbn());
        self::assertSame(2021, $libro->getAnio());

        // Al cambiarlo a Revistas, los datos de libro se vacían.
        $this->cliente->request('GET', '/es/panel/recursos/'.$libro->getId().'/editar');
        $this->cliente->submitForm('Guardar', ['recurso[tipo]' => 'revistas', 'recurso[issn]' => '0034-9887']);
        self::assertResponseRedirects('/es/panel/recursos');
        self::em()->clear();
        $revista = self::em()->find(Recurso::class, $libro->getId());
        self::assertNull($revista->getIsbn());
        self::assertNull($revista->getAnio());
        self::assertSame('0034-9887', $revista->getIssn());
    }

    public function testUnArticuloSinUrlUsaElEnlaceDelDoi(): void
    {
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $this->cliente->request('GET', '/es/panel/recursos/nuevo');
        $datos = [
            'recurso[titulo]' => 'Resistencia antimicrobiana',
            'recurso[url]' => '',
            'recurso[tipo]' => 'articulo',
            'recurso[acceso]' => 'abierto',
            'recurso[fuente]' => 'Revista Médica de Chile',
            'recurso[paginas]' => '315--322',
        ];
        $this->cliente->submitForm('Guardar', ['recurso[doi]' => 'doi.org/10.4067'] + $datos);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.campo__errores li', 'El DOI no es válido');

        $this->cliente->submitForm('Guardar', ['recurso[doi]' => 'https://doi.org/10.4067/S0034-98872021000300315'] + $datos);
        self::assertResponseRedirects('/es/panel/recursos');
        $articulo = self::em()->getRepository(Recurso::class)->findOneBy(['titulo' => 'Resistencia antimicrobiana']);
        self::assertSame('10.4067/S0034-98872021000300315', $articulo->getDoi());
        self::assertSame('https://doi.org/10.4067/S0034-98872021000300315', $articulo->getUrl());
        self::assertSame('315-322', $articulo->getPaginas());
    }

    public function testImportacionConVistaPreviaYConfirmacion(): void
    {
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $ruta = sys_get_temp_dir().'/lista-'.bin2hex(random_bytes(4)).'.xlsx';
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->fromArray([
            ['Título', 'URL', 'Materias'],
            ['Scopus', 'https://www.scopus.com', 'Medicina'],
            ['Sin URL', '', ''],
        ]);
        (new Xlsx($libro))->save($ruta);

        $crawler = $this->cliente->request('GET', '/es/panel/recursos/importar');
        $formulario = $crawler->selectButton('Revisar archivo')->form();
        $formulario['archivo']->upload($ruta);
        $crawler = $this->cliente->submit($formulario);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.fila-importacion--crear', 'Scopus');
        self::assertSelectorTextContains('.fila-importacion--error', 'Falta la URL');
        self::assertSame(0, self::em()->getRepository(Recurso::class)->count([]));

        $this->cliente->submit($crawler->selectButton('Importar 1 recurso')->form());

        self::assertResponseRedirects('/es/panel/recursos');
        $this->cliente->followRedirect();
        self::assertSelectorTextContains('.aviso--exito', '1 creados');
        self::assertSame(1, self::em()->getRepository(Recurso::class)->count([]));
        @unlink($ruta);
    }

    public function testLaVistaPreviaPermiteElegirQueImportar(): void
    {
        $medicina = self::crearMateria('Medicina', 'medicina');
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $ruta = sys_get_temp_dir().'/lista-'.bin2hex(random_bytes(4)).'.xlsx';
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->fromArray([
            ['Título', 'URL', 'Materias'],
            ['Scopus', 'https://www.scopus.com https://www.scopus.com/sources', 'Medicina; Biología marina'],
            ['PubMed', 'https://pubmed.ncbi.nlm.nih.gov', ''],
        ]);
        (new Xlsx($libro))->save($ruta);

        $crawler = $this->cliente->request('GET', '/es/panel/recursos/importar');
        $formulario = $crawler->selectButton('Revisar archivo')->form();
        $formulario['archivo']->upload($ruta);
        $crawler = $this->cliente->submit($formulario);

        self::assertCount(2, $crawler->filter('input[data-campo="url"]'));
        self::assertSame('Biología marina', $crawler->filter('input[data-campo="materia"][data-nueva]')->attr('value'));
        self::assertNotNull($crawler->filter('input[data-campo="materia"][value="'.$medicina->getId().'"]')->first()->attr('checked'));

        $confirmar = $crawler->selectButton('Importar 2 recursos')->form();
        $confirmar['seleccion'] = json_encode([
            2 => ['importar' => true, 'url' => 'https://www.scopus.com/sources', 'proxy' => true, 'materias' => ['Biología marina']],
            3 => ['importar' => false],
        ]);
        $this->cliente->submit($confirmar);

        self::assertResponseRedirects('/es/panel/recursos');
        $recursos = self::em()->getRepository(Recurso::class)->findAll();
        self::assertCount(1, $recursos);
        self::assertSame('https://www.scopus.com/sources', $recursos[0]->getUrl());
        self::assertSame(['Biología marina'], $recursos[0]->getMaterias()->map(fn ($m) => $m->getNombre())->getValues());
        @unlink($ruta);
    }

    public function testRechazaExtensionesNoPermitidas(): void
    {
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $ruta = sys_get_temp_dir().'/malicioso.php';
        file_put_contents($ruta, '<?php echo 1;');

        $crawler = $this->cliente->request('GET', '/es/panel/recursos/importar');
        $formulario = $crawler->selectButton('Revisar archivo')->form();
        $formulario['archivo']->upload($ruta);
        $this->cliente->submit($formulario);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.aviso--error', '.xlsx');
        @unlink($ruta);
    }

    public function testDescargaLaPlantilla(): void
    {
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $this->cliente->request('GET', '/es/panel/recursos/plantilla.xlsx');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
