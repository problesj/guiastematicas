<?php

declare(strict_types=1);

namespace App\Tests\Recursos;

use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Servicio\ProxyAccesum;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BasesDatosTest extends WebTestCase
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

        $crawler = $this->cliente->request('GET', '/es/bases-de-datos');
        self::assertResponseIsSuccessful();
        self::assertSame(['PubMed', 'Scopus', 'Springer eBooks'], $crawler->filter('.recurso__titulo a')->each(fn ($n) => $n->text()));
        // Solo las letras con recursos son enlaces.
        self::assertSame(['Todas', 'P', 'S'], $crawler->filter('.indice-az a')->each(fn ($n) => $n->text()));

        $crawler = $this->cliente->request('GET', '/es/bases-de-datos?letra=s');
        self::assertCount(2, $crawler->filter('.recurso'));

        $crawler = $this->cliente->request('GET', '/es/bases-de-datos?q=elsevier');
        self::assertSame(['Scopus'], $crawler->filter('.recurso__titulo a')->each(fn ($n) => $n->text()));

        $crawler = $this->cliente->request('GET', '/en/bases-de-datos?materia=medicina&acceso=abierto');
        self::assertSame(['PubMed'], $crawler->filter('.recurso__titulo a')->each(fn ($n) => $n->text()));
        self::assertSelectorTextContains('.resultados', '1 resource');

        $crawler = $this->cliente->request('GET', '/es/bases-de-datos?tipo=libros');
        self::assertSame(['Springer eBooks'], $crawler->filter('.recurso__titulo a')->each(fn ($n) => $n->text()));
    }

    public function testElEnlaceDeSalidaUsaElProxyYCuentaElClic(): void
    {
        $scopus = self::recurso('Scopus', 'https://www.scopus.com');
        $scielo = self::recurso('SciELO', 'https://www.scielo.cl', ['setRequiereProxy' => false]);

        $this->cliente->request('GET', '/bases-de-datos/'.$scopus->getId().'/ir');
        self::assertResponseRedirects('https://accesum.ucn.cl/login?url=https://www.scopus.com');

        $this->cliente->request('GET', '/bases-de-datos/'.$scielo->getId().'/ir');
        self::assertResponseRedirects('https://www.scielo.cl');

        self::em()->clear();
        self::assertSame(1, self::em()->find(Recurso::class, $scopus->getId())->getClics());
    }

    public function testElEnlaceDeUnRecursoOcultoNoFunciona(): void
    {
        $oculto = self::recurso('Oculto', 'https://oculto.org', ['setVisible' => false]);

        $this->cliente->request('GET', '/bases-de-datos/'.$oculto->getId().'/ir');

        self::assertResponseStatusCodeSame(404);
    }
}
