<?php

declare(strict_types=1);

namespace App\Tests\Panel;

use App\Entity\Guia;
use App\Entity\Recurso;
use App\Enum\Rol;
use App\Enum\TipoRecurso;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Los filtros de los listados del panel se recuerdan mientras dura la sesión. */
final class FiltrosPanelTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
    }

    public function testElListadoDeRecursosRecuerdaFiltrosYPaginaEnLaSesion(): void
    {
        $quimica = self::crearMateria('Química', 'quimica');
        for ($i = 1; $i <= 30; ++$i) {
            $recurso = (new Recurso())->setTitulo(\sprintf('Libro %02d', $i))->setUrl("https://libro$i.org")->setTipo(TipoRecurso::Libros);
            if ($i <= 12) {
                $recurso->addMateria($quimica);
            }
            self::em()->persist($recurso);
        }
        self::em()->persist((new Recurso())->setTitulo('Scopus')->setUrl('https://www.scopus.com'));
        self::em()->flush();
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));

        $crawler = $this->cliente->request('GET', '/es/panel/recursos?tipo=libros&materia=quimica&por_pagina=10&pagina=2');
        self::assertCount(2, $crawler->filter('.tabla tbody tr'));
        self::assertSelectorExists('.filtros-recordados a[href="/es/panel/recursos?limpiar=1"]');
        self::assertSame('/es/panel/recursos/exportar.xlsx?tipo=libros&materia=quimica', $crawler->filter('.exportar-recursos__enlaces a')->first()->attr('href'));

        // Al volver al listado (p. ej. después de editar) se restauran los filtros y la página.
        $this->cliente->request('GET', '/es/panel/recursos');
        self::assertResponseRedirects('/es/panel/recursos?tipo=libros&materia=quimica&por_pagina=10&pagina=2');
        $crawler = $this->cliente->followRedirect();
        self::assertSame('Libro 11', trim($crawler->filter('.tabla tbody tr td')->first()->getNode(0)->firstChild->textContent));
        self::assertSame('quimica', $crawler->filter('#filtro-materia option[selected]')->attr('value'));

        // Enviar el formulario con todo vacío olvida los filtros.
        $this->cliente->request('GET', '/es/panel/recursos?q=&tipo=&formato=&materia=');
        self::assertResponseIsSuccessful();
        $this->cliente->request('GET', '/es/panel/recursos');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.filtros-recordados a');

        // «Limpiar filtros» también.
        $this->cliente->request('GET', '/es/panel/recursos?tipo=libros');
        $this->cliente->request('GET', '/es/panel/recursos?limpiar=1');
        self::assertResponseRedirects('/es/panel/recursos');
        $this->cliente->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.exportar-recursos', '31 recursos');
    }

    public function testElListadoDeGuiasFiltraYRecuerdaLosFiltros(): void
    {
        $quimica = self::crearMateria('Química', 'quimica');
        $ana = self::crearUsuario('admin@ucn.cl', Rol::Administrador);
        $luis = self::crearUsuario('luis@ucn.cl', Rol::Editor, nombre: 'Luis', apellidos: 'Soto');
        self::em()->persist((new Guia())->setTitulo('Química general')->setSlug('quimica')->setResponsable($luis)->addMateria($quimica)->publicar());
        self::em()->persist((new Guia())->setTitulo('Química en borrador')->setSlug('quimica-b')->setResponsable($luis)->addMateria($quimica));
        self::em()->persist((new Guia())->setTitulo('Chemistry')->setSlug('chemistry')->setIdioma('en')->setResponsable($ana)->publicar());
        self::em()->flush();
        $this->cliente->loginUser($ana);

        $crawler = $this->cliente->request('GET', '/es/panel/guias');
        self::assertCount(3, $crawler->filter('.tabla tbody tr'));
        self::assertCount(3, $crawler->filter('#filtro-responsable option'));

        $crawler = $this->cliente->request('GET', '/es/panel/guias?estado=publicada&materia=quimica&responsable='.$luis->getId());
        self::assertSame(['Química general'], $crawler->filter('.tabla-guias__titulo')->each(fn ($a) => $a->text()));
        $crawler = $this->cliente->request('GET', '/es/panel/guias?idioma=en');
        self::assertSame(['Chemistry'], $crawler->filter('.tabla-guias__titulo')->each(fn ($a) => $a->text()));

        $this->cliente->request('GET', '/es/panel/guias');
        self::assertResponseRedirects('/es/panel/guias?idioma=en');

        $crawler = $this->cliente->request('GET', '/es/panel/guias?estado=borrador&idioma=en');
        self::assertSelectorTextContains('main', 'No hay guías con esos filtros.');
    }
}
