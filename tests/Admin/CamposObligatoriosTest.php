<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Enum\Rol;
use App\Tests\CreaDatosTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Enviar un formulario con los campos obligatorios vacíos debe mostrar los errores, nunca un error 500. */
final class CamposObligatoriosTest extends WebTestCase
{
    use CreaDatosTrait;

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function formularios(): iterable
    {
        yield 'recurso' => ['/es/panel/recursos/nuevo', ['recurso[titulo]' => '', 'recurso[url]' => '']];
        yield 'usuario' => ['/es/admin/usuarios/nuevo', ['usuario[email]' => '', 'usuario[nombre]' => '', 'usuario[apellidos]' => '']];
        yield 'departamento' => ['/es/admin/departamentos/nuevo', ['departamento[nombre]' => '']];
        yield 'materia' => ['/es/admin/materias/nuevo', ['materia[nombre]' => '']];
        yield 'perfil' => ['/es/panel/perfil', ['usuario[nombre]' => '', 'usuario[apellidos]' => '']];
    }

    /** @param array<string, string> $campos */
    #[DataProvider('formularios')]
    public function testMuestraLosErroresDeCamposVacios(string $ruta, array $campos): void
    {
        $cliente = static::createClient();
        $cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));

        $cliente->request('GET', $ruta);
        $crawler = $cliente->submitForm('Guardar', $campos);

        self::assertResponseStatusCodeSame(422);
        self::assertGreaterThanOrEqual(\count($campos), $crawler->filter('.campo__errores li')->count());
    }
}
