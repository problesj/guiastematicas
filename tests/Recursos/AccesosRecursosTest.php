<?php

declare(strict_types=1);

namespace App\Tests\Recursos;

use App\Entity\Recurso;
use App\Enum\Rol;
use App\Enum\TipoRecurso;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Accesos propios a Bases de datos, Libros y Revistas: se habilitan en Apariencia, en lista o cuadrícula. */
final class AccesosRecursosTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        foreach ([
            ['Scopus', TipoRecurso::BaseDatos],
            ['Web of Science', TipoRecurso::BaseDatos],
            ['Cálculo de Stewart', TipoRecurso::Libros],
            ['Nature', TipoRecurso::Revistas],
            ['ISO 9001', TipoRecurso::Normas],
        ] as [$titulo, $tipo]) {
            self::em()->persist((new Recurso())->setTitulo($titulo)->setUrl('https://ejemplo.cl/'.rawurlencode($titulo))->setTipo($tipo));
        }
        self::em()->flush();
    }

    private function configurar(array $vistas): void
    {
        $this->cliente->loginUser(self::crearUsuario('admin'.\count($vistas).random_int(1, 99999).'@ucn.cl', Rol::Administrador));
        $formulario = $this->cliente->request('GET', '/es/admin/apariencia')->selectButton('Guardar')->form();
        foreach ($vistas as $campo => $valor) {
            $formulario['form['.$campo.']'] = $valor;
        }
        $this->cliente->submit($formulario);
        self::assertResponseRedirects('/es/admin/apariencia');
        $this->cliente->request('GET', '/es/salir');
    }

    /** @return list<string> */
    private function menu(): array
    {
        return $this->cliente->request('GET', '/es/guias')->filter('.menu a')->each(fn ($a) => $a->text());
    }

    public function testDeshabilitadosNoAparecenNiResponden(): void
    {
        self::assertSame(['Guías por materia', 'Recursos bibliográficos', 'Bibliotecarios'], $this->menu());
        $this->cliente->request('GET', '/es/recursos-bibliograficos/bases-de-datos');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCadaAccesoMuestraSoloSuTipoEnListaOCuadricula(): void
    {
        $this->configurar(['VISTA_BASE_DATOS' => 'cuadricula', 'VISTA_REVISTAS' => 'lista']);
        self::assertSame(['Guías por materia', 'Recursos bibliográficos', 'Bases de datos', 'Revistas', 'Bibliotecarios'], $this->menu());

        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos/bases-de-datos');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bases de datos A-Z');
        self::assertSame(['Scopus', 'Web of Science'], $crawler->filter('.recurso__titulo a')->each(fn ($a) => $a->text()));
        self::assertCount(2, $crawler->filter('ul.recursos--cuadricula .recurso__icono--base_datos'));
        self::assertCount(0, $crawler->filter('select#tipo'));
        // El índice A-Z solo enlaza las letras de este tipo y se queda en la vista.
        self::assertSame(['/es/recursos-bibliograficos/bases-de-datos', '/es/recursos-bibliograficos/bases-de-datos?letra=S', '/es/recursos-bibliograficos/bases-de-datos?letra=W'], $crawler->filter('.indice-az a')->each(fn ($a) => $a->attr('href')));

        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos/revistas');
        self::assertSame(['Nature'], $crawler->filter('ul.recursos--lista .recurso__titulo a')->each(fn ($a) => $a->text()));

        $this->cliente->request('GET', '/es/recursos-bibliograficos/libros');
        self::assertResponseStatusCodeSame(404);

        // La dirección antigua /bases-de-datos lleva a la nueva vista.
        $this->cliente->request('GET', '/es/bases-de-datos?q=scopus');
        self::assertResponseRedirects('/es/recursos-bibliograficos/bases-de-datos?q=scopus');

        // Y se puede volver a deshabilitar.
        $this->configurar(['VISTA_BASE_DATOS' => '', 'VISTA_REVISTAS' => '']);
        $this->cliente->request('GET', '/es/recursos-bibliograficos/bases-de-datos');
        self::assertResponseStatusCodeSame(404);
    }
}
