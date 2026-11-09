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
