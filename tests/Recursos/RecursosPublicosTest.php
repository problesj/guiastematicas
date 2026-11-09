<?php

declare(strict_types=1);

namespace App\Tests\Recursos;

use App\Entity\Autor;
use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Servicio\ProxyAccesum;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RecursosPublicosTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        static::getContainer()->set(ProxyAccesum::class, new ProxyAccesum('https://accesum.ucn.cl/login?url='));
    }

    private static function recurso(string $titulo, string $url, array $cambios = []): Recurso
    {
        $recurso = (new Recurso())->setTitulo($titulo)->setUrl($url);
        foreach ($cambios as $metodo => $valor) {
            $recurso->{$metodo}($valor);
        }
        self::em()->persist($recurso);
        self::em()->flush();

        return $recurso;
    }

    public function testListaAZConFiltros(): void
    {
        $medicina = self::crearMateria('Medicina', 'medicina');
        self::recurso('Scopus', 'https://www.scopus.com', ['setProveedor' => 'Elsevier']);
        self::recurso('PubMed', 'https://pubmed.ncbi.nlm.nih.gov', ['addMateria' => $medicina, 'setAcceso' => TipoAcceso::Abierto]);
        self::recurso('Springer eBooks', 'https://link.springer.com', ['setTipo' => TipoRecurso::Libros]);
        self::recurso('Oculto', 'https://oculto.org', ['setVisible' => false]);
        self::recurso('Prueba vencida', 'https://vencida.org', ['setAcceso' => TipoAcceso::Prueba, 'setPruebaHasta' => new \DateTimeImmutable('-1 day')]);

        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos');
        self::assertResponseIsSuccessful();
        self::assertSame(['PubMed', 'Scopus', 'Springer eBooks'], $crawler->filter('.recurso__titulo a')->each(fn ($n) => $n->text()));
        // Los enlaces a las bases se abren en una ventana nueva, con aviso para lectores de pantalla.
        self::assertSame(['_blank'], array_unique($crawler->filter('.recurso__titulo a')->each(fn ($n) => $n->attr('target'))));
        self::assertCount(1, $crawler->filter('#aviso-ventana-nueva'));
        // Solo las letras con recursos son enlaces.
        self::assertSame(['Todas', 'P', 'S'], $crawler->filter('.indice-az a')->each(fn ($n) => $n->text()));

        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?letra=s');
        self::assertCount(2, $crawler->filter('.recurso'));

        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?q=elsevier');
        self::assertSame(['Scopus'], $crawler->filter('.recurso__titulo a')->each(fn ($n) => $n->text()));

        $crawler = $this->cliente->request('GET', '/en/recursos-bibliograficos?materia=medicina&acceso=abierto');
        self::assertSame(['PubMed'], $crawler->filter('.recurso__titulo a')->each(fn ($n) => $n->text()));
        self::assertSelectorTextContains('.resultados', '1 resource');

        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?tipo=libros');
        self::assertSame(['Springer eBooks'], $crawler->filter('.recurso__titulo a')->each(fn ($n) => $n->text()));
    }

    public function testElEnlaceDeSalidaUsaElProxyYCuentaElClic(): void
    {
        $scopus = self::recurso('Scopus', 'https://www.scopus.com');
        $scielo = self::recurso('SciELO', 'https://www.scielo.cl', ['setRequiereProxy' => false]);

        $this->cliente->request('GET', '/recursos/'.$scopus->getId().'/ir');
        self::assertResponseRedirects('https://accesum.ucn.cl/login?url=https://www.scopus.com');

        $this->cliente->request('GET', '/recursos/'.$scielo->getId().'/ir');
        self::assertResponseRedirects('https://www.scielo.cl');

        self::em()->clear();
        self::assertSame(1, self::em()->find(Recurso::class, $scopus->getId())->getClics());
    }

    public function testElEnlaceDeUnRecursoOcultoNoFunciona(): void
    {
        $oculto = self::recurso('Oculto', 'https://oculto.org', ['setVisible' => false]);

        $this->cliente->request('GET', '/recursos/'.$oculto->getId().'/ir');

        self::assertResponseStatusCodeSame(404);
    }

    public function testMuestraYBuscaLosDatosBibliograficos(): void
    {
        self::recurso('Introducción a la programación', 'https://ebooks.org/1', [
            'setTipo' => TipoRecurso::Libros, 'setAutores' => [Autor::desdeTexto('Pérez, Ana')], 'setIsbn' => '978-956-11-2345-8', 'setAnio' => 2021,
        ]);
        self::recurso('Revista Médica de Chile', 'https://revista.cl', ['setTipo' => TipoRecurso::Revistas, 'setIssn' => '00349887']);

        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Recursos bibliográficos');
        $datos = $crawler->filter('.recurso__datos')->each(fn ($n) => $n->text());
        self::assertStringContainsString('ISBN978-956-11-2345-8', $datos[0]);
        self::assertStringContainsString('Año de edición2021', $datos[0]);
        self::assertStringContainsString('ISSN0034-9887', $datos[1]);

        foreach (['978-956-11-2345-8' => 'Introducción a la programación', 'pérez' => 'Introducción a la programación', '0034-9887' => 'Revista Médica de Chile'] as $busqueda => $esperado) {
            $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?q='.rawurlencode($busqueda));
            self::assertSame([$esperado], $crawler->filter('.recurso__titulo a')->each(fn ($n) => $n->text()), $busqueda);
        }
    }

    public function testLaListaSeMuestraPorPaginas(): void
    {
        $materia = self::crearMateria('Medicina', 'medicina');
        for ($i = 1; $i <= 12; ++$i) {
            self::recurso(\sprintf('Recurso %02d', $i), "https://recurso$i.org", ['addMateria' => $materia]);
        }

        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?por_pagina=10&materia=medicina');
        self::assertCount(10, $crawler->filter('.recurso'));
        self::assertSelectorTextContains('.resultados', '12');
        self::assertSelectorTextContains('.paginacion-cantidad__resumen', 'Mostrando 1–10 de 12');
        self::assertSame('10', $crawler->filter('#por-pagina option[selected]')->attr('value'));
        // El selector conserva los filtros.
        self::assertSame('medicina', $crawler->filter('.paginacion-cantidad input[name="materia"]')->attr('value'));

        $siguiente = $crawler->filter('.paginacion a[rel="next"]')->attr('href');
        self::assertStringContainsString('pagina=2', $siguiente);
        self::assertStringContainsString('por_pagina=10', $siguiente);
        self::assertStringContainsString('materia=medicina', $siguiente);

        $crawler = $this->cliente->request('GET', $siguiente);
        self::assertSame(['Recurso 11', 'Recurso 12'], $crawler->filter('.recurso__titulo a')->each(fn ($n) => $n->text()));
        self::assertSame('Página 2', $crawler->filter('.paginacion a[aria-current="page"]')->text());

        // Una página fuera de rango muestra la última; una cantidad no permitida usa la predeterminada (25).
        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?por_pagina=10&pagina=99');
        self::assertCount(2, $crawler->filter('.recurso'));
        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?por_pagina=7');
        self::assertCount(12, $crawler->filter('.recurso'));
        self::assertCount(0, $crawler->filter('.paginacion'));
    }

    public function testLaDireccionAntiguaRedirige(): void
    {
        $scopus = self::recurso('Scopus', 'https://www.scopus.com');

        $this->cliente->request('GET', '/en/bases-de-datos?letra=S');
        self::assertResponseRedirects('/en/recursos-bibliograficos?letra=S', 301);

        // Los enlaces de salida antiguos siguen funcionando.
        $this->cliente->request('GET', '/bases-de-datos/'.$scopus->getId().'/ir');
        self::assertResponseRedirects('https://accesum.ucn.cl/login?url=https://www.scopus.com');
    }

    public function testArticuloConDoiYVideoIncrustado(): void
    {
        self::recurso('Resistencia antimicrobiana', 'https://doi.org/10.4067/S0034-98872021000300315', [
            'setTipo' => TipoRecurso::Articulo, 'setFuente' => 'Revista Médica de Chile', 'setDoi' => '10.4067/S0034-98872021000300315',
        ]);
        self::recurso('Cómo buscar en Scopus', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', ['setTipo' => TipoRecurso::Multimedia]);
        self::recurso('Scopus', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'); // no es multimedia: sin reproductor

        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos');

        self::assertSame('https://doi.org/10.4067/S0034-98872021000300315', $crawler->filter('.recurso__datos a')->attr('href'));
        self::assertStringContainsString('Fuente (revista)Revista Médica de Chile', $crawler->filter('.recurso__datos')->text());
        self::assertCount(1, $crawler->filter('.recurso__video iframe'));
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $crawler->filter('.recurso__video iframe')->attr('src'));
    }
}
