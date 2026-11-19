<?php

declare(strict_types=1);

namespace App\Tests\Recursos;

use App\Clasificacion\SincronizadorAsjc;
use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Recurso;
use App\Enum\Rol;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Repository\MateriaRepository;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Las categorías ASJC son las materias base; las propias se clasifican en ellas y aparecen al filtrar por ellas. */
final class MateriasBaseAsjcTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    private static function sincronizar(): array
    {
        $resultado = SincronizadorAsjc::sincronizar(self::em()->getConnection());
        self::em()->clear();

        return $resultado;
    }

    private static function materia(string $slug): Materia
    {
        return self::em()->getRepository(Materia::class)->findOneBy(['slug' => $slug]);
    }

    public function testSeCreanLas334CategoriasReutilizandoLasExistentesPorNombre(): void
    {
        $derecho = self::crearMateria('Derecho', 'derecho');
        $law = self::crearMateria('Ciencias del mar', 'ciencias-del-mar')->setNombreEn('Oceanography');
        self::em()->flush();

        self::assertSame(['creadas' => 332, 'unidas' => 2], self::sincronizar());
        self::assertSame(['creadas' => 0, 'unidas' => 0], self::sincronizar()); // se puede repetir

        $repo = self::em()->getRepository(Materia::class);
        self::assertSame(334, $repo->count([]));
        self::assertSame(3308, $repo->find($derecho->getId())->getCodigoAsjc());
        self::assertSame(1910, $repo->find($law->getId())->getCodigoAsjc());
        $civil = $repo->findOneBy(['codigoAsjc' => 2205]);
        self::assertSame(['Ingeniería civil y estructural', 'Civil and Structural Engineering', 'ingenieria-civil-y-estructural'], [$civil->getNombre(), $civil->getNombreEn(), $civil->getSlug()]);
        self::assertSame([2205], $civil->getAsjc());
    }

    public function testFiltrarPorUnaCategoriaIncluyeSusMateriasPropias(): void
    {
        self::sincronizar();
        $derecho = self::materia('derecho');
        $minero = self::crearMateria('Derecho minero', 'derecho-minero')->setAsjc([3308]);
        $medicina = self::materia('medicina-general');
        $responsable = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        foreach ([['Código de Minería', $minero], ['Leyes de Chile', $derecho], ['PubMed', $medicina]] as [$titulo, $materia]) {
            self::em()->persist((new Recurso())->setTitulo($titulo)->setUrl('https://ejemplo.cl/'.md5($titulo))->setTipo(TipoRecurso::BaseDatos)->setAcceso(TipoAcceso::Abierto)->addMateria($materia));
        }
        $guia = (new Guia())->setTitulo('Minería y ley')->setSlug('mineria')->setResponsable($responsable)->addMateria($minero);
        $guia->publicar();
        self::em()->persist($guia);
        self::em()->flush();

        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?materia=derecho');
        self::assertSame(['Código de Minería', 'Leyes de Chile'], $crawler->filter('.recurso__titulo a')->each(fn ($a) => $a->text()));
        // El filtro ofrece solo las materias con contenido, agrupadas por campo ASJC; las propias bajo su categoría.
        self::assertSame(['', 'medicina-general', 'derecho', 'derecho-minero'], $crawler->filter('#materia option')->each(fn ($o) => $o->attr('value')));
        self::assertSame(['2700 · Medicina', '3300 · Ciencias sociales'], $crawler->filter('#materia optgroup')->each(fn ($g) => $g->attr('label')));
        self::assertSame('— Derecho minero', $crawler->filter('#materia option[value="derecho-minero"]')->text());

        $crawler = $this->cliente->request('GET', '/es/guias?materia=derecho');
        self::assertSame(['Minería y ley'], $crawler->filter('.lista-guias__guia h2 a')->each(fn ($a) => $a->text()));
        $crawler = $this->cliente->request('GET', '/es/guias?materia=medicina-general');
        self::assertCount(0, $crawler->filter('.lista-guias__guia'));

        $relacionadas = static::getContainer()->get(MateriaRepository::class)->relacionadas([self::materia('derecho-minero')]);
        self::assertEqualsCanonicalizing(['derecho-minero', 'derecho'], array_map(fn (Materia $m) => $m->getSlug(), $relacionadas));
    }

    public function testAdministracionDeMateriasBaseYPropias(): void
    {
        self::sincronizar();
        $this->cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));
        $sinClasificar = self::crearMateria('Taller de tesis', 'taller-de-tesis');

        $crawler = $this->cliente->request('GET', '/es/admin/materias');
        self::assertSelectorTextContains('.aviso--error', '1 materia propia sin clasificar');
        // Por omisión no se listan las 334 categorías: solo las propias (y las categorías en uso).
        self::assertCount(1, $crawler->filter('tbody tr:not(.tabla__grupo)'));
        $crawler = $this->cliente->request('GET', '/es/admin/materias?todas=1');
        self::assertCount(335, $crawler->filter('tbody tr:not(.tabla__grupo)'));

        // Una categoría ASJC se edita sin el selector de clasificación y no se puede eliminar.
        $derecho = self::materia('derecho');
        $crawler = $this->cliente->request('GET', '/es/admin/materias/'.$derecho->getId().'/editar');
        self::assertCount(0, $crawler->filter('input[name="materia[asjc][]"]'));
        $this->cliente->request('POST', '/es/admin/materias/'.$derecho->getId().'/eliminar');
        self::assertResponseRedirects('/es/admin/materias');
        self::assertNotNull(self::em()->find(Materia::class, $derecho->getId()));
        self::assertNotNull(self::em()->find(Materia::class, $sinClasificar->getId()));
    }

    public function testSelectoresDeMaterias(): void
    {
        self::sincronizar();
        $responsable = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $this->cliente->loginUser($responsable);
        $minero = self::crearMateria('Derecho minero', 'derecho-minero')->setAsjc([3308]);
        self::em()->flush();

        // Formulario de recurso: casillas agrupadas por campo, la propia tras su categoría.
        $crawler = $this->cliente->request('GET', '/es/panel/recursos/nuevo');
        self::assertCount(27, $crawler->filter('[data-controller="filtro-asjc"] details.asjc__campo'));
        $sociales = $crawler->filter('details.asjc__campo')->reduce(fn ($d) => str_starts_with(trim($d->filter('summary')->text()), '3300'));
        $etiquetas = $sociales->filter('.casillas__opcion')->each(fn ($l) => trim($l->text()));
        self::assertSame('3308 · Derecho', $etiquetas[array_search('Derecho minero', $etiquetas, true) - 1]);

        // Catálogo para el selector compacto de libros y revistas.
        $this->cliente->request('GET', '/es/materias/catalogo');
        $catalogo = json_decode((string) $this->cliente->getResponse()->getContent(), true);
        self::assertCount(335, $catalogo);
        $fila = array_values(array_filter($catalogo, fn ($m) => $m['id'] === $minero->getId()))[0];
        self::assertSame(['etiqueta' => 'Derecho minero', 'campo' => 'Ciencias sociales'], ['etiqueta' => $fila['etiqueta'], 'campo' => $fila['campo']]);
        self::assertStringContainsString('3308', $fila['texto']);
    }
}
