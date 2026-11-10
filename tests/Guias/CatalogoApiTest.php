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
use App\Enum\TipoAcceso;
use App\Enum\TipoBloque;
use App\Enum\TipoRecurso;
use App\Enum\VisibilidadGuia;
use App\Tests\CatalogoFalso;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Fase 5: libros por ISBN con datos del catálogo y disponibilidad de Alma, API pública e incrustación de bloques. */
final class CatalogoApiTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    private static function crearGuia(Usuario $responsable, string $slug = 'quimica', VisibilidadGuia $visibilidad = VisibilidadGuia::Publica): Guia
    {
        $guia = (new Guia())->setTitulo(ucfirst($slug))->setSlug($slug)->setResponsable($responsable)->setVisibilidad($visibilidad);
        $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion(new Seccion('Libros')));
        $guia->publicar();
        self::em()->persist($guia);
        self::em()->flush();

        return $guia;
    }

    /** @param array<string, mixed> $campos */
    private function enviarLibros(string $url, array $items, string $isbns = ''): void
    {
        $crawler = $this->cliente->request('GET', $url);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="bloque[_token]"]')->attr('value');
        $this->cliente->request('POST', $url, ['bloque' => ['titulo' => 'Bibliografía', 'items' => $items, 'isbns' => $isbns, '_token' => $token]]);
    }

    public function testConElIsbnElLibroSeCompletaDesdeElCatalogoYMuestraSuDisponibilidad(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana);
        $seccion = $guia->getPestana()->getSecciones()->first();
        $this->cliente->loginUser($ana);
        $url = '/es/panel/guias/'.$guia->getId().'/secciones/'.$seccion->getId().'/bloques/libros';

        // Un ISBN que el catálogo no conoce necesita el título escrito a mano.
        $this->enviarLibros($url, [['isbn' => '978-1-234-56789-7']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.coleccion__item', 'No encontramos el ISBN 978-1-234-56789-7');
        $this->enviarLibros($url, [['isbn' => '', 'titulo' => '']], '9781234567897');
        self::assertResponseStatusCodeSame(422);

        // Una fila y dos ISBN pegados de una vez.
        $this->enviarLibros($url, [['isbn' => '', 'titulo' => 'Apuntes del curso', 'enlace' => 'https://ejemplo.cl/apuntes']], "978-0-262-03384-8\n9780134685991");
        self::assertResponseRedirects();
        self::em()->clear();
        $bloque = self::em()->getRepository(Bloque::class)->findOneBy(['seccion' => $seccion->getId()]);
        $items = $bloque->dato('items');
        self::assertSame(['Apuntes del curso', 'Introduction to algorithms', 'Effective Java'], array_column($items, 'titulo'));
        self::assertSame('Cormen, Thomas H.', $items[1]['autor']);
        self::assertSame('2009', $items[1]['anio']);
        self::assertSame(CatalogoFalso::MMS_CORMEN, $items[1]['mms']);
        self::assertSame('2018', $items[2]['anio']);
        self::assertNull($items[2]['mms']); // solo en el índice central: sin ejemplares en Alma

        // Lo escrito a mano prevalece; el registro se recalcula desde el ISBN.
        $this->enviarLibros('/es/panel/guias/'.$guia->getId().'/bloques/'.$bloque->getId().'/editar', [
            ['isbn' => '9780262033848', 'titulo' => 'Algoritmos (Cormen)', 'autor' => '', 'enlace' => ''],
        ]);
        self::assertResponseRedirects();
        self::em()->clear();
        $libro = self::em()->find(Bloque::class, $bloque->getId())->dato('items')[0];
        self::assertSame('Algoritmos (Cormen)', $libro['titulo']);
        self::assertSame('Cormen, Thomas H.', $libro['autor']);
        self::assertSame('alma'.CatalogoFalso::MMS_CORMEN, $libro['registro']);

        $this->cliente->request('GET', '/es/guias/quimica');
        self::assertSelectorTextContains('.libro__edicion', 'Cambridge, Mass. : MIT Press, 2009');
        self::assertSelectorExists('.bloque__libros[data-controller="disponibilidad"]');

        // Disponibilidad: la primera biblioteca con ejemplares disponibles.
        $this->cliente->request('GET', '/es/catalogo/disponibilidad/'.$bloque->getId());
        self::assertResponseIsSuccessful();
        self::assertSame([CatalogoFalso::MMS_CORMEN => ['disponible' => true, 'texto' => 'Disponible en Biblioteca Coquimbo · QA76.6 C662']], json_decode((string) $this->cliente->getResponse()->getContent(), true));
    }

    public function testLaApiEntregaSoloLasGuiasPublicas(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana);
        $restringida = self::crearGuia($ana, 'taller', VisibilidadGuia::Restringida);
        $scopus = (new Recurso())->setTitulo('Scopus')->setUrl('https://www.scopus.com')->setTipo(TipoRecurso::BaseDatos)->setAcceso(TipoAcceso::Suscripcion);
        self::em()->persist($scopus);
        self::em()->flush();
        $seccion = $guia->getPestana()->getSecciones()->first();
        $seccion->agregarBloque((new Bloque(TipoBloque::Recursos))->setTitulo('Bases')->setContenido(['ids' => [$scopus->getId()]]));
        $seccion->agregarBloque((new Bloque(TipoBloque::Libros))->setContenido(['items' => [['titulo' => 'Introduction to algorithms', 'isbn' => '9780262033848', 'registro' => 'alma'.CatalogoFalso::MMS_CORMEN]]]));
        self::em()->flush();

        $this->cliente->request('GET', '/api/v1/guias');
        self::assertResponseIsSuccessful();
        self::assertSame('*', $this->cliente->getResponse()->headers->get('Access-Control-Allow-Origin'));
        $lista = json_decode((string) $this->cliente->getResponse()->getContent(), true);
        self::assertSame(['quimica'], array_column($lista['guias'], 'slug'));
        self::assertSame('http://localhost/es/guias/quimica', $lista['guias'][0]['url']);

        $this->cliente->request('GET', '/api/v1/guias/quimica');
        $detalle = json_decode((string) $this->cliente->getResponse()->getContent(), true);
        [$bases, $libros] = $detalle['pestanas'][0]['secciones'][0]['bloques'];
        self::assertSame('Scopus', $bases['recursos'][0]['titulo']);
        self::assertSame('http://localhost/recursos/'.$scopus->getId().'/ir', $bases['recursos'][0]['url']);
        self::assertStringStartsWith('https://primo.ejemplo.cl/discovery/fulldisplay?docid=alma', $libros['libros'][0]['url']);
        self::assertSame('http://localhost/es/incrustar/bloque/'.$libros['id'], $libros['incrustar']);

        $this->cliente->request('GET', '/api/v1/guias/taller');
        self::assertResponseStatusCodeSame(404);
        $this->cliente->request('GET', '/api/v1/recursos?q=scopus');
        self::assertSame(1, json_decode((string) $this->cliente->getResponse()->getContent(), true)['total']);

        // Nada de la guía restringida se puede incrustar ni consultar sin permiso.
        $bloqueRestringido = (new Bloque(TipoBloque::Libros))->setContenido(['items' => [['titulo' => 'X', 'mms' => CatalogoFalso::MMS_CORMEN]]]);
        $restringida->getPestana()->getSecciones()->first()->agregarBloque($bloqueRestringido);
        self::em()->flush();
        $this->cliente->request('GET', '/es/incrustar/bloque/'.$bloqueRestringido->getId());
        self::assertResponseStatusCodeSame(404);
        $this->cliente->request('GET', '/es/catalogo/disponibilidad/'.$bloqueRestringido->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnBloqueDeUnaGuiaPublicaSePuedeIncrustarEnOtraWeb(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana);
        $bloque = (new Bloque(TipoBloque::Enlaces))->setTitulo('Normas')->setContenido(['items' => [['titulo' => 'INN', 'url' => 'https://www.inn.cl', 'descripcion' => null]]]);
        $guia->getPestana()->getSecciones()->first()->agregarBloque($bloque);
        self::em()->flush();

        $crawler = $this->cliente->request('GET', '/es/incrustar/bloque/'.$bloque->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('frame-ancestors *', $this->cliente->getResponse()->headers->get('Content-Security-Policy'));
        self::assertSame('_blank', $crawler->filter('base')->attr('target'));
        self::assertSelectorTextContains('h2.bloque__titulo', 'Normas');
        self::assertSame('https://www.inn.cl', $crawler->filter('.bloque__enlaces a')->attr('href'));

        $this->cliente->loginUser($ana);
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId());
        $this->cliente->click($crawler->selectLink('Incrustar')->link());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<div data-guias-bloque="'.$bloque->getId().'"></div>', $this->cliente->getCrawler()->filter('#codigo-script')->text());
        self::assertStringContainsString('src="http://localhost/es/incrustar/bloque/'.$bloque->getId().'"', $this->cliente->getCrawler()->filter('#codigo-iframe')->text());
    }

    public function testElComandoPruebaLaConexionConElCatalogo(): void
    {
        $comando = new CommandTester((new Application(self::$kernel))->find('app:probar-catalogo'));

        self::assertSame(0, $comando->execute(['isbn' => '978-0-262-03384-8']));
        self::assertStringContainsString('Biblioteca Coquimbo', $comando->getDisplay());
        self::assertSame(1, $comando->execute(['isbn' => '9781234567897']));
    }
}
