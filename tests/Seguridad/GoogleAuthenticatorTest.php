<?php

declare(strict_types=1);

namespace App\Tests\Seguridad;

use App\Repository\UsuarioRepository;
use App\Security\GoogleAuthenticator;
use App\Servicio\MetodosAcceso;
use App\Tests\CreaDatosTrait;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/** Reglas para asociar una cuenta de Google con una persona registrada. */
final class GoogleAuthenticatorTest extends KernelTestCase
{
    use CreaDatosTrait;

    private function autenticador(string $dominio = ''): GoogleAuthenticator
    {
        $c = static::getContainer();

        return new GoogleAuthenticator(
            $c->get(ClientRegistry::class),
            $c->get(UsuarioRepository::class),
            $c->get(UrlGeneratorInterface::class),
            $c->get(MetodosAcceso::class),
            $dominio,
        );
    }

    public function testAsociaPorCorreoYGuardaElIdDeGoogle(): void
    {
        self::bootKernel();
        $ana = self::crearUsuario('ana@ucn.cl');

        $usuario = $this->autenticador()->buscarUsuario('g-123', 'ana@ucn.cl', true);

        self::assertSame($ana, $usuario);
        self::assertSame('g-123', $usuario->getGoogleId());
        self::em()->flush(); // lo hace RegistroAccesoSubscriber al terminar el ingreso
        // En el siguiente ingreso se reconoce por el id aunque cambie el correo en Google.
        self::assertSame($ana, $this->autenticador()->buscarUsuario('g-123', 'otro@gmail.com', true));
    }

    public function testRechazaCorreosNoRegistrados(): void
    {
        self::bootKernel();
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->autenticador()->buscarUsuario('g-1', 'desconocido@ucn.cl', true);
    }

    public function testRechazaCorreosNoVerificados(): void
    {
        self::bootKernel();
        self::crearUsuario('ana@ucn.cl');
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->autenticador()->buscarUsuario('g-1', 'ana@ucn.cl', false);
    }

    public function testRestringeAlDominioConfigurado(): void
    {
        self::bootKernel();
        self::crearUsuario('ana@gmail.com');
        $this->expectExceptionMessage('acceso.google_dominio');

        $this->autenticador('ucn.cl')->buscarUsuario('g-1', 'ana@gmail.com', true);
    }

    public function testNoReemplazaUnaCuentaDeGoogleYaVinculada(): void
    {
        self::bootKernel();
        self::crearUsuario('ana@ucn.cl')->setGoogleId('g-original');
        self::em()->flush();
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->autenticador()->buscarUsuario('g-otra', 'ana@ucn.cl', true);
    }
}
