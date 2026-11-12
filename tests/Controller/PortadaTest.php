<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Recurso;
use App\Entity\Seccion;
use App\Enum\Rol;
use App\Enum\VisibilidadGuia;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PortadaTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
    }

    private static function recurso(string $titulo, bool $visible = true): Recurso
    {
        $recurso = (new Recurso())->setTitulo($titulo)->setUrl('https://ejemplo.cl/'.rawurlencode($titulo))->setVisible($visible);
        self::em()->persist($recurso);
        self::em()->flush();

        return $recurso;
    }

    private static function guia(string $titulo, string $slug, VisibilidadGuia $visibilidad = VisibilidadGuia::Publica): Guia
    {
        $guia = (new Guia())->setTitulo($titulo)->setSlug($slug)->setResponsable(self::crearUsuario($slug.'@ucn.cl', Rol::Editor))->setVisibilidad($visibilidad);
        $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion(new Seccion()));
        $guia->publicar();
        self::em()->persist($guia);
        self::em()->flush();

        return $guia;
    }

    public function testPortadaConBuscadorModulosYRecursosRecientes(): void
    {
        for ($i = 1; $i <= 12; ++$i) {
            self::recurso('Recurso '.$i);
        }
        self::recurso('Recurso oculto', false);

        $crawler = $this->cliente->request('GET', '/es');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.portada__antetitulo', 'Bienvenidos a');
        self::assertSelectorExists('form.portada__busqueda[action="/es/buscar"] input[name="q"]');
        self::assertSelectorTextContains('.portada__busqueda', 'Buscar en el sitio');
        self::assertCount(3, $crawler->filter('.modulos .modulo a'));
        self::assertSelectorExists('.modulo a[href="/es/guias"]');
        // Sin imagen propia, la cabecera usa el degradado de los colores del sitio.
        self::assertSelectorNotExists('.portada--imagen');

        // Los 10 más recientes (los visibles), del más nuevo al más antiguo.
        $titulos = $crawler->filter('.recientes .libro__titulo')->each(fn ($n) => $n->text());
        self::assertSame(array_map(fn ($i) => 'Recurso '.$i, range(12, 3)), $titulos);
    }

    public function testBuscarEnElSitioEncuentraGuiasYRecursos(): void
    {
        self::guia('Química general', 'quimica');
        self::guia('Química restringida', 'quimica-privada', VisibilidadGuia::Restringida);
        self::guia('Historia', 'historia');
        self::recurso('Revista de química');
        self::recurso('Base de historia');

        $crawler = $this->cliente->request('GET', '/es/buscar?q=química');
        self::assertResponseIsSuccessful();
        self::assertSame(['Química general'], $crawler->filter('.buscar__lista')->eq(0)->filter('li > a')->each(fn ($n) => $n->text()));
        self::assertStringContainsString('Revista de química', $crawler->filter('.buscar__lista')->eq(1)->text());
        self::assertStringNotContainsString('Base de historia', $crawler->filter('.buscar__lista')->eq(1)->text());

        $this->cliente->request('GET', '/en/buscar?q=zzz');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No guides match your search.');
    }
}
