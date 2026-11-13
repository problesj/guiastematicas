<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Configuracion\Ajustes;
use App\Enum\Rol;
use App\Servicio\BloquesGuia;
use App\Servicio\ProxyAccesum;
use App\Tests\CreaDatosTrait;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConfiguracionTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
    }

    private function guardar(array $campos): void
    {
        $crawler = $this->cliente->request('GET', '/es/admin/configuracion');
        $formulario = $crawler->selectButton('Guardar')->form();
        $this->cliente->submit($formulario, array_combine(array_map(fn ($c) => 'form['.$c.']', array_keys($campos)), $campos));
    }

    public function testElAdministradorDefineLasIntegracionesYLasClavesQuedanCifradas(): void
    {
        $this->cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));

        // Sin nada en el panel rige el entorno de pruebas (.env.test).
        $crawler = $this->cliente->request('GET', '/es/admin/configuracion');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('se usa el valor del servidor («https://primo.ejemplo.cl»)', $crawler->filter('.configuracion__ajuste')->eq(1)->text());

        $this->guardar(['PROXY_URL' => 'https://proxy.ucn.cl/login?url=', 'PRIMO_VID' => '56UCN_INST:OTRA', 'ALMA_API_KEY' => 'clave-secreta-alma', 'LDAP_CIFRADO' => 'tls']);
        self::assertResponseRedirects('/es/admin/configuracion');

        // Los servicios usan lo del panel; lo vacío sigue tomando el entorno.
        $contenedor = static::getContainer();
        self::assertSame('https://proxy.ucn.cl/login?url=https://www.scopus.com', $contenedor->get(ProxyAccesum::class)->aplicar('https://www.scopus.com'));
        self::assertStringContainsString('vid=56UCN_INST:OTRA', $contenedor->get(BloquesGuia::class)->enlacePrimo('agua', 'es'));
        self::assertStringStartsWith('https://primo.ejemplo.cl/', $contenedor->get(BloquesGuia::class)->enlacePrimo('agua', 'es'));
        self::assertSame('clave-secreta-alma', $contenedor->get(Ajustes::class)->valor('ALMA_API_KEY'));

        // En la base de datos la clave está cifrada, y la página nunca la muestra.
        $guardada = $contenedor->get(Connection::class)->fetchOne("SELECT valor FROM ajuste WHERE clave = 'ALMA_API_KEY'");
        self::assertStringNotContainsString('clave-secreta-alma', $guardada);
        $crawler = $this->cliente->request('GET', '/es/admin/configuracion');
        self::assertStringNotContainsString('clave-secreta-alma', (string) $this->cliente->getResponse()->getContent());
        self::assertStringContainsString('Clave guardada en el panel', $crawler->filter('.configuracion__ajuste')->eq(5)->text());

        // Guardar sin escribir la clave la conserva; «Quitar» la borra; vaciar un campo vuelve al valor del servidor.
        $this->guardar(['PRIMO_VID' => '']);
        self::assertSame('clave-secreta-alma', static::getContainer()->get(Ajustes::class)->valor('ALMA_API_KEY'));
        self::assertNull(static::getContainer()->get(Ajustes::class)->valor('PRIMO_VID'));
        $crawler = $this->cliente->request('GET', '/es/admin/configuracion');
        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['form[ALMA_API_KEY__borrar]']->tick();
        $this->cliente->submit($formulario);
        self::assertNull(static::getContainer()->get(Ajustes::class)->valor('ALMA_API_KEY'));

        // Una dirección mal escrita no se acepta.
        $this->guardar(['PRIMO_URL' => 'primo.ucn.cl']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.formulario--configuracion', 'Escribe una dirección completa');
    }

    public function testSoloElAdministradorEntra(): void
    {
        $this->cliente->loginUser(self::crearUsuario('ana@ucn.cl', Rol::Editor));
        $this->cliente->request('GET', '/es/admin/configuracion');
        self::assertResponseStatusCodeSame(403);
        $this->cliente->request('GET', '/es/admin/configuracion/wordpress.zip');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDescargaElPluginDeWordpressConLaDireccionDelServidor(): void
    {
        $this->cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));
        $crawler = $this->cliente->request('GET', '/es/admin/configuracion');
        self::assertSelectorExists('a[href="/es/admin/configuracion/wordpress.zip"]');

        $this->cliente->request('GET', '/es/admin/configuracion/wordpress.zip');
        $contenido = $this->cliente->getInternalResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('guias-ucn.zip', (string) $this->cliente->getResponse()->headers->get('Content-Disposition'));

        $archivo = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($archivo, $contenido);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archivo));
        self::assertNotFalse($zip->locateName('guias-ucn/guias-ucn.php'));
        self::assertNotFalse($zip->locateName('guias-ucn/assets/guias-ucn.css'));
        self::assertStringContainsString("return 'http://localhost';", (string) $zip->getFromName('guias-ucn/servidor.php'));
        $zip->close();
        unlink($archivo);
    }

    public function testLaApiEntregaElIconoYLaGuiaPrincipal(): void
    {
        $principal = (new \App\Entity\Guia())->setTitulo('Salud')->setSlug('salud');
        $guia = (new \App\Entity\Guia())->setTitulo('Enfermería')->setSlug('enfermeria')->setIcono('icono-abc.png')->setPrincipal($principal);
        foreach ([$principal, $guia] as $g) {
            $g->agregarPestana((new \App\Entity\Pestana('Inicio'))->agregarSeccion(new \App\Entity\Seccion()));
            $g->publicar();
            self::em()->persist($g);
        }
        self::em()->flush();

        $this->cliente->request('GET', '/api/v1/guias/enfermeria');
        $datos = json_decode((string) $this->cliente->getResponse()->getContent(), true);
        self::assertSame('http://localhost/uploads/guias/icono-abc.png', $datos['icono']);
        self::assertSame(['titulo' => 'Salud', 'slug' => 'salud'], $datos['principal']);
    }
}
