<?php

declare(strict_types=1);

namespace App\Tests\Comando;

use App\Enum\Rol;
use App\Repository\UsuarioRepository;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CrearAdministradorCommandTest extends KernelTestCase
{
    use CreaDatosTrait;

    private function comando(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('app:crear-administrador'));
    }

    public function testCreaAdministradorConContrasena(): void
    {
        $comando = $this->comando();
        $comando->setInputs(['clave-del-administrador']);
        $comando->execute(['email' => 'Jefa@UCN.cl', 'nombre' => 'Jefa', 'apellidos' => 'Biblioteca']);

        $comando->assertCommandIsSuccessful();
        $usuario = static::getContainer()->get(UsuarioRepository::class)->findOneBy(['email' => 'jefa@ucn.cl']);
        self::assertSame(Rol::Administrador, $usuario->getRol());
        self::assertTrue($usuario->tieneContrasenaPropia());
    }

    public function testPromueveAUnaPersonaExistenteSinContrasena(): void
    {
        $comando = $this->comando();
        self::crearUsuario('ana@ucn.cl', Rol::Colaborador, contrasena: null);

        $comando->execute(['email' => 'ana@ucn.cl', '--ldap' => 'aperez', '--sin-contrasena' => true]);

        $comando->assertCommandIsSuccessful();
        $ana = static::getContainer()->get(UsuarioRepository::class)->findOneBy(['email' => 'ana@ucn.cl']);
        self::assertSame(Rol::Administrador, $ana->getRol());
        self::assertSame('aperez', $ana->getLdapUsuario());
    }
}
