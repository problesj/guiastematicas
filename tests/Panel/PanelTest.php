<?php

declare(strict_types=1);

namespace App\Tests\Panel;

use App\Entity\Usuario;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PanelTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testEditaSuPerfil(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl');
        $this->cliente->loginUser($ana);

        $this->cliente->request('GET', '/es/panel/perfil');
        $this->cliente->submitForm('Guardar', [
            'usuario[nombre]' => 'Ana María',
            'usuario[cargo]' => 'Bibliotecaria de referencia',
            'usuario[cargoEn]' => 'Reference librarian',
        ]);

        self::assertResponseRedirects('/es/panel/perfil');
        self::em()->clear();
        $ana = self::em()->find(Usuario::class, $ana->getId());
        self::assertSame('Ana María', $ana->getNombre());
        self::assertSame('ana-maria-perez', $ana->getSlug());
        self::assertSame('Reference librarian', $ana->getCargoTraducido('en'));
    }

    public function testElPerfilNoPermiteCambiarRolNiCorreo(): void
    {
        $this->cliente->loginUser(self::crearUsuario('ana@ucn.cl'));
        $crawler = $this->cliente->request('GET', '/es/panel/perfil');

        self::assertCount(0, $crawler->filter('[name="usuario[rol]"]'));
        self::assertCount(0, $crawler->filter('[name="usuario[email]"]'));
    }

    public function testCambiaSuContrasena(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl');
        $this->cliente->loginUser($ana);

        $this->cliente->request('GET', '/es/panel/contrasena');
        $this->cliente->submitForm('Cambiar contraseña', [
            'cambio_contrasena[actual]' => self::CONTRASENA,
            'cambio_contrasena[nueva][first]' => 'Biblioteca-Angamos-2026!',
            'cambio_contrasena[nueva][second]' => 'Biblioteca-Angamos-2026!',
        ]);

        self::assertResponseRedirects('/es/panel');
        self::em()->clear();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid(self::em()->find(Usuario::class, $ana->getId()), 'Biblioteca-Angamos-2026!'));
    }

    public function testRechazaContrasenaActualIncorrecta(): void
    {
        $this->cliente->loginUser(self::crearUsuario('ana@ucn.cl'));

        $this->cliente->request('GET', '/es/panel/contrasena');
        $this->cliente->submitForm('Cambiar contraseña', [
            'cambio_contrasena[actual]' => 'no-es-la-actual',
            'cambio_contrasena[nueva][first]' => 'Biblioteca-Angamos-2026!',
            'cambio_contrasena[nueva][second]' => 'Biblioteca-Angamos-2026!',
        ]);

        self::assertSelectorTextContains('.campo__errores', 'La contraseña actual no es correcta');
    }

    public function testSinContrasenaPropiaNoMuestraElFormulario(): void
    {
        $this->cliente->loginUser(self::crearUsuario('ana@ucn.cl', contrasena: null, ldap: 'aperez'));
        $this->cliente->request('GET', '/es/panel/contrasena');

        self::assertSelectorNotExists('form.formulario');
        self::assertSelectorTextContains('main', 'LDAP UCN');
    }
}
