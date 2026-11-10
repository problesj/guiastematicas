<?php

declare(strict_types=1);

namespace App\Tests\Seguridad;

use App\Enum\Rol;
use App\Repository\UsuarioRepository;
use App\Security\VerificadorLdapInterface;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccesoTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
    }

    private function entrar(string $identificador, string $contrasena): void
    {
        $this->cliente->request('GET', '/es/acceso');
        $this->cliente->submitForm('Entrar', ['identificador' => $identificador, 'contrasena' => $contrasena]);
    }

    public function testElFormularioDeAccesoSeMuestra(): void
    {
        $this->cliente->request('GET', '/es/acceso');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Iniciar sesión');
        // Sin GOOGLE_CLIENT_ID no se ofrece Google.
        self::assertSelectorNotExists('a[href^="/conectar/google"]');
    }

    public function testEntraConContrasenaPropia(): void
    {
        self::crearUsuario('ana@ucn.cl');
        $this->entrar('ANA@ucn.cl', self::CONTRASENA);

        self::assertResponseRedirects('/es/panel');
        $this->cliente->followRedirect();
        self::assertSelectorTextContains('h1', 'Hola, Ana');
        self::assertNotNull(static::getContainer()->get(UsuarioRepository::class)->findOneBy(['email' => 'ana@ucn.cl'])->getUltimoAcceso());
    }

    public function testRechazaContrasenaIncorrecta(): void
    {
        self::crearUsuario('ana@ucn.cl');
        $this->entrar('ana@ucn.cl', 'otra-clave-cualquiera');

        self::assertResponseRedirects('/es/acceso');
        $this->cliente->followRedirect();
        self::assertSelectorTextContains('.aviso--error', 'Usuario o contraseña incorrectos');
    }

    public function testRechazaUsuarioInexistenteConElMismoMensaje(): void
    {
        $this->entrar('nadie@ucn.cl', self::CONTRASENA);

        $this->cliente->followRedirect();
        self::assertSelectorTextContains('.aviso--error', 'Usuario o contraseña incorrectos');
    }

    public function testRechazaCuentaInactiva(): void
    {
        self::crearUsuario('ana@ucn.cl', activo: false);
        $this->entrar('ana@ucn.cl', self::CONTRASENA);

        $this->cliente->followRedirect();
        self::assertSelectorTextContains('.aviso--error', 'desactivada');
    }

    public function testEntraConLdapUsandoElUsuarioInstitucional(): void
    {
        static::getContainer()->set(VerificadorLdapInterface::class, new LdapFalso('aperez', 'clave-ldap'));
        self::crearUsuario('ana@ucn.cl', contrasena: null, ldap: 'aperez');

        $this->entrar('aperez', 'clave-ldap');

        self::assertResponseRedirects('/es/panel');
    }

    public function testLdapRechazaContrasenaIncorrectaOVacia(): void
    {
        static::getContainer()->set(VerificadorLdapInterface::class, new LdapFalso('aperez', 'clave-ldap'));
        self::crearUsuario('ana@ucn.cl', contrasena: null, ldap: 'aperez');

        $this->entrar('aperez', 'equivocada');
        self::assertResponseRedirects('/es/acceso');

        $this->entrar('aperez', '');
        self::assertResponseRedirects('/es/acceso');
    }

    public function testLdapNoSeUsaSiLaPersonaNoTieneUsuarioLdap(): void
    {
        static::getContainer()->set(VerificadorLdapInterface::class, new LdapFalso('ana@ucn.cl', 'clave-ldap'));
        self::crearUsuario('ana@ucn.cl', contrasena: null);

        $this->entrar('ana@ucn.cl', 'clave-ldap');

        self::assertResponseRedirects('/es/acceso');
    }

    public function testElPanelExigeIniciarSesion(): void
    {
        $this->cliente->request('GET', '/en/panel');

        self::assertResponseRedirects('/en/acceso');
    }

    public function testSoloLosAdministradoresEntranAAdministracion(): void
    {
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $this->cliente->request('GET', '/es/admin/usuarios');
        self::assertResponseStatusCodeSame(403);

        $this->cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));
        $this->cliente->request('GET', '/es/admin/usuarios');
        self::assertResponseIsSuccessful();
    }

    public function testCerrarSesion(): void
    {
        $this->cliente->loginUser(self::crearUsuario('ana@ucn.cl'));
        $crawler = $this->cliente->request('GET', '/es/panel');
        $this->cliente->click($crawler->selectLink('Cerrar sesión')->link());

        self::assertResponseRedirects('/');
        $this->cliente->request('GET', '/es/panel');
        self::assertResponseRedirects('/es/acceso');
    }
}
