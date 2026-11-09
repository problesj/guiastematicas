<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\Materia;
use App\Entity\Usuario;
use App\Enum\Rol;
use App\Repository\UsuarioRepository;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdministracionTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;
    private Usuario $admin;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        // Los formularios usan CSRF sin estado (el navegador envía Origin).
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->admin = self::crearUsuario('admin@ucn.cl', Rol::Administrador, nombre: 'Admin', apellidos: 'UCN');
        $this->cliente->loginUser($this->admin);
    }

    public function testCreaUnaPersonaConContrasenaYLdap(): void
    {
        $materia = self::crearMateria('Medicina', 'medicina');
        $departamento = self::crearDepartamento('Referencia');

        $this->cliente->request('GET', '/es/admin/usuarios/nuevo');
        $this->cliente->submitForm('Guardar', [
            'usuario[email]' => 'Carla.Diaz@UCN.cl',
            'usuario[rol]' => Rol::Editor->value,
            'usuario[nombre]' => 'Carla',
            'usuario[apellidos]' => 'Díaz Muñoz',
            'usuario[departamento]' => (string) $departamento->getId(),
            'usuario[materias]' => [(string) $materia->getId()],
            'usuario[ldapUsuario]' => 'cdiaz',
            'usuario[nuevaContrasena]' => 'una-clave-suficientemente-larga',
            'usuario[activo]' => true,
            'usuario[visibleEnDirectorio]' => true,
        ]);

        self::assertResponseRedirects('/es/admin/usuarios');
        $carla = static::getContainer()->get(UsuarioRepository::class)->findOneBy(['email' => 'carla.diaz@ucn.cl']);
        self::assertNotNull($carla);
        self::assertSame('carla-diaz-munoz', $carla->getSlug());
        self::assertSame('cdiaz', $carla->getLdapUsuario());
        self::assertTrue($carla->tieneContrasenaPropia());
        self::assertSame(Rol::Editor, $carla->getRol());
        self::assertCount(1, $carla->getMaterias());
    }

    public function testRechazaCorreoRepetido(): void
    {
        self::crearUsuario('ana@ucn.cl');

        $this->cliente->request('GET', '/es/admin/usuarios/nuevo');
        $this->cliente->submitForm('Guardar', [
            'usuario[email]' => 'ana@ucn.cl',
            'usuario[nombre]' => 'Otra',
            'usuario[apellidos]' => 'Ana',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.campo__errores', 'Ya existe una persona con este correo');
    }

    public function testNoPermiteQuitarElUltimoAdministrador(): void
    {
        $this->cliente->request('GET', '/es/admin/usuarios/'.$this->admin->getId().'/editar');
        $this->cliente->submitForm('Guardar', ['usuario[rol]' => Rol::Editor->value]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.campo__errores', 'al menos un administrador activo');
    }

    public function testCreaDepartamento(): void
    {
        $this->cliente->request('GET', '/es/admin/departamentos/nuevo');
        $this->cliente->submitForm('Guardar', [
            'departamento[nombre]' => 'Biblioteca Coquimbo',
            'departamento[sede]' => 'coquimbo',
            'departamento[orden]' => '2',
        ]);

        self::assertResponseRedirects('/es/admin/departamentos');
        $this->cliente->followRedirect();
        self::assertSelectorTextContains('table', 'Biblioteca Coquimbo');
    }

    public function testEliminaDepartamentoYDejaASusIntegrantesSinDepartamento(): void
    {
        $departamento = self::crearDepartamento('Temporal');
        $ana = self::crearUsuario('ana@ucn.cl')->setDepartamento($departamento);
        self::em()->flush();

        $crawler = $this->cliente->request('GET', '/es/admin/departamentos');
        $this->cliente->submit($crawler->filter('form.en-linea')->form());

        self::assertResponseRedirects('/es/admin/departamentos');
        self::em()->clear();
        self::assertNull(self::em()->find(Usuario::class, $ana->getId())->getDepartamento());
    }

    public function testCreaMateriaYGeneraElIdentificador(): void
    {
        self::crearMateria('Ingeniería Civil', 'ingenieria-civil');

        $this->cliente->request('GET', '/en/admin/materias/nuevo');
        $this->cliente->submitForm('Save', ['materia[nombre]' => 'Ingeniería Civil', 'materia[activa]' => true]);

        self::assertResponseRedirects('/en/admin/materias');
        self::assertNotNull(self::em()->getRepository(Materia::class)->findOneBy(['slug' => 'ingenieria-civil-2']));
    }

    public function testRechazaIdentificadorDeMateriaInvalido(): void
    {
        $this->cliente->request('GET', '/es/admin/materias/nuevo');
        $this->cliente->submitForm('Guardar', ['materia[nombre]' => 'Derecho', 'materia[slug]' => 'Con Espacios']);

        self::assertResponseStatusCodeSame(422);
    }
}
