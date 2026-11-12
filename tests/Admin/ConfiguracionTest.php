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
    }
}
