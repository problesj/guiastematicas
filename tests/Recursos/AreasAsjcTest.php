<?php

declare(strict_types=1);

namespace App\Tests\Recursos;

use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Recurso;
use App\Enum\Rol;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Materias asociadas a categorías ASJC de Scopus y filtro por gran área en guías y lista A-Z. */
final class AreasAsjcTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    private function datos(): void
    {
        $responsable = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        foreach ([['Medicina', 'medicina', [2700]], ['Derecho', 'derecho', [3308]], ['Arte', 'arte', []]] as [$nombre, $slug, $asjc]) {
            $materia = self::crearMateria($nombre, $slug)->setAsjc($asjc);
            $recurso = (new Recurso())->setTitulo('Base de '.$nombre)->setUrl('https://ejemplo.cl/'.$slug)->setTipo(TipoRecurso::BaseDatos)->setAcceso(TipoAcceso::Abierto)->addMateria($materia);
            $guia = (new Guia())->setTitulo('Guía de '.$nombre)->setSlug($slug)->setResponsable($responsable)->addMateria($materia);
            $guia->publicar();
            self::em()->persist($recurso);
            self::em()->persist($guia);
        }
        self::em()->flush();
    }

    public function testElAdministradorAsociaCategoriasAsjcAUnaMateria(): void
    {
        $this->cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));
        $crawler = $this->cliente->request('GET', '/es/admin/materias/nuevo');
        self::assertCount(334, $crawler->filter('input[name="materia[asjc][]"]'));
        self::assertCount(27, $crawler->filter('details.asjc__campo'));

        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['materia[nombre]'] = 'Derecho';
        foreach ($formulario['materia[asjc]'] as $casilla) {
            if (\in_array($casilla->availableOptionValues()[0], ['3308', '3312'], true)) {
                $casilla->tick();
            }
        }
        $this->cliente->submit($formulario);
        self::assertResponseRedirects('/es/admin/materias');

        $materia = self::em()->getRepository(Materia::class)->findOneBy(['slug' => 'derecho']);
        self::assertSame([3308, 3312], $materia->getAsjc());

        $crawler = $this->cliente->request('GET', '/es/admin/materias');
        self::assertSame('Derecho', $crawler->filter('abbr[title]')->first()->attr('title'));
    }

    public function testFiltroPorGranAreaEnGuias(): void
    {
        $this->datos();
        $crawler = $this->cliente->request('GET', '/es/guias');
        self::assertSame(['Todas las áreas', 'Ciencias de la salud', 'Ciencias sociales y humanidades'], $crawler->filter('.filtro-areas a')->each(fn ($a) => trim($a->text())));
        self::assertCount(3, $crawler->filter('.lista-guias__guia'));

        $crawler = $this->cliente->request('GET', '/es/guias?area=salud');
        self::assertSame(['Guía de Medicina'], $crawler->filter('.lista-guias__guia h2 a')->each(fn ($a) => $a->text()));
        self::assertSame('Ciencias de la salud', $crawler->filter('.filtro-areas a[aria-current]')->text());
        self::assertSame(['', 'medicina'], $crawler->filter('#materia option')->each(fn ($o) => $o->attr('value')));

        // Un área sin materias se ignora.
        $crawler = $this->cliente->request('GET', '/es/guias?area=fisicas');
        self::assertCount(3, $crawler->filter('.lista-guias__guia'));
    }

    public function testFiltroPorGranAreaEnLaListaAZ(): void
    {
        $this->datos();
        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?area=sociales');
        self::assertSame(['Base de Derecho'], $crawler->filter('.recurso__titulo a')->each(fn ($a) => $a->text()));
        self::assertSame('sociales', $crawler->filter('form.filtros input[name="area"]')->attr('value'));
        self::assertStringContainsString('area=sociales', $crawler->filter('.indice-az a[href*="letra=B"]')->attr('href'));
    }

    public function testSinCategoriasNoApareceElFiltro(): void
    {
        $responsable = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = (new Guia())->setTitulo('Arte')->setSlug('arte')->setResponsable($responsable)->addMateria(self::crearMateria('Arte', 'arte'));
        $guia->publicar();
        self::em()->persist($guia);
        self::em()->flush();
        $crawler = $this->cliente->request('GET', '/es/guias');
        self::assertCount(0, $crawler->filter('.filtro-areas'));
    }
}
