<?php

declare(strict_types=1);

namespace App\Tests\Guias;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Recurso;
use App\Entity\Seccion;
use App\Entity\Usuario;
use App\Enum\Rol;
use App\Enum\TipoRecurso;
use App\Tests\CatalogoFalso;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Bloque Revistas: revistas a mano o por ISSN (datos de la búsqueda de revistas de Primo VE), buscador por título o
 * categoría (también pegando el enlace de Primo), incorporación a la lista de recursos y vista en la guía.
 */
final class BloqueRevistasTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;
    private Usuario $ana;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $this->cliente->loginUser($this->ana);
    }

    private function crearGuia(): Guia
    {
        $guia = (new Guia())->setTitulo('Medicina')->setSlug('medicina')->setResponsable($this->ana);
        $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion(new Seccion('Revistas')));
        $guia->addMateria(self::crearMateria('Medicina', 'medicina'));
        self::em()->persist($guia);
        self::em()->flush();

        return $guia;
    }

    /** @return array<string, mixed> */
    private function json(string $url, int $estado = 200): array
    {
        $this->cliente->request('GET', $url);
        self::assertResponseStatusCodeSame($estado);

        return json_decode((string) $this->cliente->getResponse()->getContent(), true);
    }

    public function testLasRevistasSeCompletanPorIssnYQuedanEnLaListaDeRecursos(): void
    {
        $guia = $this->crearGuia();
        $seccion = $guia->getPestana()->getSecciones()->first();
        $url = '/es/panel/guias/'.$guia->getId().'/secciones/'.$seccion->getId().'/bloques/revistas';

        $crawler = $this->cliente->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="coleccion paginar-libros"] [data-controller="buscador-revistas"]'));
        self::assertSelectorTextContains('.paginar-libros__tamano', 'Revistas por página');
        $plantilla = $crawler->filter('template[data-coleccion-target="plantilla"]')->html();
        foreach (['coleccion__item--revista', 'revista-datos#buscar', 'Editar', 'Borrar'] as $texto) {
            self::assertStringContainsString($texto, $plantilla);
        }
        $token = $crawler->filter('input[name="bloque[_token]"]')->attr('value');

        $this->cliente->request('POST', $url, ['bloque' => [
            'titulo' => 'Revistas de anatomía',
            'presentacion' => 'lista',
            'columnas' => '0',
            'orden' => 'titulo',
            'items' => [['titulo' => 'Revista chilena de anatomía', 'enlace' => 'https://revistas.ejemplo.cl/anatomia']],
            'issns' => "00219290\n1234",
            '_token' => $token,
        ]]);
        self::assertResponseRedirects();
        self::em()->clear();

        $bloque = self::em()->getRepository(Bloque::class)->findOneBy(['seccion' => $seccion->getId()]);
        $items = $bloque->dato('items');
        // Ordenadas por título; la del ISSN con los datos del catálogo (y el otro ISSN, el electrónico).
        self::assertSame(['Journal of biomechanics', 'Revista chilena de anatomía'], array_column($items, 'titulo'));
        self::assertSame('0021-9290', $items[0]['issn']);
        self::assertSame('1873-2380', $items[0]['eissn']);
        self::assertSame('Elsevier Science', $items[0]['editorial']); // sin el lugar de publicación
        self::assertSame('alma99641303307936', $items[0]['registro']);

        $revista = self::em()->find(Recurso::class, $items[0]['recurso']);
        self::assertSame(TipoRecurso::Revistas, $revista->getTipo());
        self::assertSame('0021-9290', $revista->getIssn());
        self::assertSame('1873-2380', $revista->getEissn());
        self::assertStringStartsWith('https://primo.ejemplo.cl/discovery/fulldisplay?docid=alma99641303307936', $revista->getUrl());
        self::assertSame(TipoRecurso::Revistas, self::em()->find(Recurso::class, $items[1]['recurso'])->getTipo());
        self::assertSame(2, self::em()->getRepository(Recurso::class)->count([]));

        // Volver a guardar no duplica los recursos.
        $editar = '/es/panel/guias/'.$guia->getId().'/bloques/'.$bloque->getId().'/editar';
        $crawler = $this->cliente->request('GET', $editar);
        self::assertSame('Journal of biomechanics', $crawler->filter('.coleccion__item--revista [data-fila="titulo"]')->first()->text());
        $this->cliente->submit($crawler->filter('form.formulario')->form());
        self::assertResponseRedirects();
        self::assertSame(2, self::em()->getRepository(Recurso::class)->count([]));

        // En la guía publicada: lista breve con editorial, ISSN y enlace al registro del catálogo.
        self::em()->clear();
        $guia = self::em()->find(Guia::class, $guia->getId());
        $guia->publicar();
        self::em()->flush();
        $crawler = $this->cliente->request('GET', '/es/guias/medicina');
        self::assertResponseIsSuccessful();
        $primera = $crawler->filter('.libro--revista')->first();
        self::assertStringContainsString('ISSN 0021-9290 · e-ISSN 1873-2380', $primera->text());
        self::assertStringContainsString('docid=alma99641303307936', $primera->filter('.libro__titulo a')->attr('href'));
        self::assertSame('https://revistas.ejemplo.cl/anatomia', $crawler->filter('.libro--revista')->eq(1)->filter('.libro__titulo a')->attr('href'));
    }

    public function testUnIssnQueNoEstaEnElCatalogoPideElTitulo(): void
    {
        $guia = $this->crearGuia();
        $seccion = $guia->getPestana()->getSecciones()->first();
        $url = '/es/panel/guias/'.$guia->getId().'/secciones/'.$seccion->getId().'/bloques/revistas';
        $crawler = $this->cliente->request('GET', $url);
        $token = $crawler->filter('input[name="bloque[_token]"]')->attr('value');

        $this->cliente->request('POST', $url, ['bloque' => ['items' => [['issn' => '2049-3630']], 'issns' => '', 'orden' => 'manual', 'presentacion' => 'cuadricula', 'columnas' => '0', '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.coleccion__item--revista', 'No encontramos la revista 2049-3630');
        self::assertSame(0, self::em()->getRepository(Recurso::class)->count([]));

        $this->cliente->request('POST', $url, ['bloque' => ['items' => [['issn' => '0021-9291']], 'issns' => '', 'orden' => 'manual', 'presentacion' => 'cuadricula', 'columnas' => '0', '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.coleccion__item--revista', 'El ISSN no es válido');
    }

    public function testBuscarDatosPorIssn(): void
    {
        $datos = $this->json('/es/panel/guias/datos-revista?issn=1873-2380');
        self::assertSame('Journal of biomechanics', $datos['titulo']);
        self::assertSame(['1873-2380', '0021-9290'], [$datos['issn'], $datos['eissn']]);
        self::assertSame('no_encontrado', $this->json('/es/panel/guias/datos-revista?issn=2049-3630', 404)['error']);
    }

    public function testEntregaElArbolDeCategoriasDeRevistasDePrimo(): void
    {
        $arbol = $this->json('/es/panel/guias/buscar-revistas/categorias')['categorias'];
        // Ordenadas por nombre; la ruta une los códigos de cada nivel con "─", como en journals=category,<ruta>.
        self::assertSame(['Ciencias de la salud y biológicas', 'Economía y empresa'], array_column($arbol, 'nombre'));
        self::assertSame(['Anatomía humana y fisiología', 'Biología'], array_column($arbol[0]['hijos'], 'nombre'));
        self::assertSame('Health_Biological_Sciences─Human_Anatomy_Physiology', $arbol[0]['hijos'][0]['ruta']);
        $biologia = $arbol[0]['hijos'][1];
        // Sin traducción, el código con espacios.
        self::assertSame(['Botánica', 'Microbiology'], array_column($biologia['hijos'], 'nombre'));
        self::assertSame('Health_Biological_Sciences─Biology─Microbiology', $biologia['hijos'][1]['ruta']);

        $datos = $this->json('/es/panel/guias/buscar-revistas/catalogo?categoria='.rawurlencode('Health_Biological_Sciences─Human_Anatomy_Physiology'));
        self::assertSame(925, $datos['total']);
    }

    public function testBuscaRevistasPorTituloCategoriaOEnlaceDePrimo(): void
    {
        $datos = $this->json('/es/panel/guias/buscar-revistas/catalogo?texto=biomechanics');
        self::assertSame(['Journal of biomechanics'], array_column($datos['revistas'], 'titulo'));

        $datos = $this->json('/es/panel/guias/buscar-revistas/catalogo?categoria='.rawurlencode('Health_Biological_Sciences─Human_Anatomy_Physiology'));
        self::assertSame(925, $datos['total']);
        self::assertSame(47, $datos['paginas']);
        self::assertSame(['Annals of anatomy', 'Journal of biomechanics'], array_column($datos['revistas'], 'titulo'));

        // El enlace tal como se copia de la búsqueda de revistas de Primo.
        $enlace = 'https://ucn.primo.exlibrisgroup.com/discovery/jsearch?query=contains,dbcategory,&tab=jsearch_slot&sortby=title&vid=56UCN_INST:UCN&lang=es&offset=0&journals=category,Health_Biological_Sciences%E2%94%80Human_Anatomy_Physiology';
        $datos = $this->json('/es/panel/guias/buscar-revistas/catalogo?categoria='.rawurlencode($enlace));
        self::assertCount(2, $datos['revistas']);
        self::assertSame('0940-9602', $datos['revistas'][0]['issn']);
        self::assertNull($datos['revistas'][0]['enlace']);

        self::assertSame('parametros', $this->json('/es/panel/guias/buscar-revistas/catalogo?categoria=', 400)['error']);

        // Si Primo rechaza la consulta, el editor ve el código y el mensaje de Primo (sin la clave).
        $datos = $this->json('/es/panel/guias/buscar-revistas/catalogo?categoria=Categoria_Mala', 502);
        self::assertSame('HTTP 400 Invalid journals parameter', $datos['detalle']);
        self::assertStringNotContainsString('clave-primo', (string) $this->cliente->getResponse()->getContent());

        // La dirección que se envía a Primo lleva la categoría en "journals" con la coma tal cual.
        $consultas = static::getContainer()->get(CatalogoFalso::class)->consultas;
        $categoria = array_values(array_filter($consultas, fn (string $u) => str_contains($u, 'Human_Anatomy_Physiology')))[0];
        self::assertStringContainsString('tab=jsearch_slot', $categoria);
        self::assertStringContainsString('q=any,contains,*', $categoria);
        self::assertStringContainsString('journals=category,Health_Biological_Sciences%E2%94%80Human_Anatomy_Physiology', $categoria);
        self::assertStringContainsString('sort=title', $categoria);
    }
}
