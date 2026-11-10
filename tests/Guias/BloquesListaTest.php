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
use App\Servicio\AlmacenImagenesGuia;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Bloques con lista de elementos (enlaces, libros, diapositivas), recurso libre y botón de cita del perfil. */
final class BloquesListaTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    private static function crearGuia(Usuario $responsable): Guia
    {
        $guia = (new Guia())->setTitulo('Química')->setSlug('quimica')->setResponsable($responsable);
        $inicio = new Pestana('Inicio');
        $guia->agregarPestana($inicio);
        $inicio->agregarSeccion(new Seccion('Recursos'));
        $guia->publicar();
        self::em()->persist($guia);
        self::em()->flush();

        return $guia;
    }

    /** Envía el formulario del bloque con los elementos indicados (como lo arma el controlador "coleccion"). */
    private function enviar(string $url, array $items, array $archivos = []): void
    {
        $crawler = $this->cliente->request('GET', $url);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="bloque[_token]"]')->attr('value');
        $this->cliente->request('POST', $url, ['bloque' => ['titulo' => 'Lista', 'items' => $items, '_token' => $token]], $archivos ? ['bloque' => ['items' => $archivos]] : []);
    }

    private static function bloques(Seccion $seccion): array
    {
        self::em()->clear();

        return self::em()->getRepository(Bloque::class)->findBy(['seccion' => $seccion->getId()], ['posicion' => 'ASC']);
    }

    public function testEnlacesYLibrosSeValidanGuardanYMuestran(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana);
        $seccion = $guia->getPestana()->getSecciones()->first();
        $this->cliente->loginUser($ana);
        $base = '/es/panel/guias/'.$guia->getId().'/secciones/'.$seccion->getId().'/bloques/';

        // La plantilla para agregar elementos viene en el formulario.
        $crawler = $this->cliente->request('GET', $base.'enlaces');
        self::assertCount(1, $crawler->filter('[data-controller="coleccion"] template[data-coleccion-target="plantilla"]'));

        $this->enviar($base.'enlaces', []);
        self::assertResponseStatusCodeSame(422);
        $this->enviar($base.'enlaces', [['titulo' => 'INN', 'url' => 'javascript:alert(1)']]);
        self::assertResponseStatusCodeSame(422);
        $this->enviar($base.'enlaces', [
            ['titulo' => 'IEEE Xplore', 'url' => 'https://ieeexplore.ieee.org/', 'descripcion' => 'Normas'],
            ['titulo' => '', 'url' => '', 'descripcion' => ''], // fila vacía: se descarta
            ['titulo' => 'INN', 'url' => 'www.inn.cl', 'descripcion' => ''],
        ]);
        self::assertResponseRedirects();

        $this->enviar($base.'libros', [['titulo' => 'Algoritmos', 'isbn' => '123']]);
        self::assertResponseStatusCodeSame(422);
        $this->enviar($base.'libros', [
            ['titulo' => 'Introduction to Algorithms', 'autor' => 'Cormen', 'isbn' => '978-0-262-03384-8', 'enlace' => ''],
            ['titulo' => 'Sin ISBN', 'autor' => '', 'isbn' => '', 'enlace' => 'https://ejemplo.cl/libro'],
        ]);
        self::assertResponseRedirects();

        [$enlaces, $libros] = self::bloques($seccion);
        self::assertEquals([ // MySQL guarda el JSON con las claves en otro orden
            ['titulo' => 'IEEE Xplore', 'url' => 'https://ieeexplore.ieee.org/', 'descripcion' => 'Normas'],
            ['titulo' => 'INN', 'url' => 'https://www.inn.cl', 'descripcion' => null],
        ], $enlaces->dato('items'));
        self::assertSame('9780262033848', $libros->dato('items')[0]['isbn']);

        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId());
        self::assertStringContainsString('2 enlaces: IEEE Xplore, INN', $crawler->filter('#bloque-'.$enlaces->getId())->text());

        $crawler = $this->cliente->request('GET', '/es/guias/quimica');
        self::assertSame(['IEEE Xplore', 'INN'], $crawler->filter('.bloque__enlaces > li > a')->each(fn ($a) => $a->filter('.solo-lectores')->count() ? trim(str_replace($a->filter('.solo-lectores')->text(), '', $a->text())) : $a->text()));
        self::assertSame('https://covers.openlibrary.org/b/isbn/9780262033848-M.jpg?default=false', $crawler->filter('.libro img')->attr('src'));
        self::assertSame('https://ejemplo.cl/libro', $crawler->filter('.libro')->eq(1)->filter('a')->attr('href'));
        // Encontrado en el catálogo por ISBN: enlaza a su registro en Primo VE.
        self::assertStringStartsWith('https://primo.ejemplo.cl/discovery/fulldisplay?docid=alma', $crawler->filter('.libro')->eq(0)->filter('a')->attr('href'));

        // El orden de la pantalla se respeta al volver a guardar.
        $this->enviar('/es/panel/guias/'.$guia->getId().'/bloques/'.$libros->getId().'/editar', [
            ['titulo' => 'Sin ISBN', 'enlace' => 'https://ejemplo.cl/libro'],
            ['titulo' => 'Introduction to Algorithms', 'isbn' => '9780262033848'],
        ]);
        self::assertResponseRedirects();
        self::assertSame(['Sin ISBN', 'Introduction to Algorithms'], array_column(self::em()->find(Bloque::class, $libros->getId())->dato('items'), 'titulo'));
    }

    public function testLasDiapositivasGuardanSusImagenesYBorranLasQueYaNoSeUsan(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana);
        $seccion = $guia->getPestana()->getSecciones()->first();
        $this->cliente->loginUser($ana);
        $png = sys_get_temp_dir().'/diapositiva.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $archivo = function () use ($png): UploadedFile { // cada subida mueve su archivo: una copia por vez
            $copia = tempnam(sys_get_temp_dir(), 'dia');
            copy($png, $copia);

            return new UploadedFile($copia, 'diapositiva.png', 'image/png', null, true);
        };
        $url = '/es/panel/guias/'.$guia->getId().'/secciones/'.$seccion->getId().'/bloques/diapositivas';
        $directorio = static::getContainer()->getParameter('kernel.project_dir').'/public/'.AlmacenImagenesGuia::RUTA_PUBLICA;

        // Sin imagen no se acepta; tampoco una imagen inventada (el campo oculto llega del navegador).
        $this->enviar($url, [['titulo' => 'Consejo', 'imagen' => '']]);
        self::assertResponseStatusCodeSame(422);
        $this->enviar($url, [['titulo' => 'Consejo', 'imagen' => '../../.env']]);
        self::assertResponseRedirects();
        self::assertSame([], self::bloques($seccion)[0]->dato('items'));
        $this->cliente->request('POST', '/es/panel/guias/'.$guia->getId().'/bloques/'.self::bloques($seccion)[0]->getId().'/eliminar', [
            '_token' => $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId())->filter('[data-ordenar-token-value]')->attr('data-ordenar-token-value'),
        ]);

        $this->enviar($url, [['titulo' => 'Agenda tu capacitación', 'enlace' => 'https://bibliotecas.ucn.cl/'], ['titulo' => 'Segunda']], [['archivo' => $archivo()], ['archivo' => $archivo()]]);
        self::assertResponseRedirects();
        $bloque = self::bloques($seccion)[0];
        $items = $bloque->dato('items');
        self::assertCount(2, $items);
        self::assertMatchesRegularExpression('/^d'.$guia->getId().'-[0-9a-f]{24}\.png$/', $items[0]['imagen']);
        self::assertFileExists($directorio.'/'.$items[1]['imagen']);

        $crawler = $this->cliente->request('GET', '/es/guias/quimica');
        self::assertCount(2, $crawler->filter('.diapositivas__diapositiva img'));
        self::assertSame('Agenda tu capacitación', $crawler->filter('.diapositivas__diapositiva img')->attr('alt'));
        self::assertSame('https://bibliotecas.ucn.cl/', $crawler->filter('.diapositivas__diapositiva a')->attr('href'));

        // Al quitar la segunda se borra su imagen; al eliminar el bloque, la otra.
        $this->enviar('/es/panel/guias/'.$guia->getId().'/bloques/'.$bloque->getId().'/editar', [['imagen' => $items[0]['imagen'], 'titulo' => 'Agenda tu capacitación']]);
        self::assertResponseRedirects();
        self::assertFileExists($directorio.'/'.$items[0]['imagen']);
        self::assertFileDoesNotExist($directorio.'/'.$items[1]['imagen']);

        $token = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId())->filter('[data-ordenar-token-value]')->attr('data-ordenar-token-value');
        $this->cliente->request('POST', '/es/panel/guias/'.$guia->getId().'/bloques/'.$bloque->getId().'/eliminar', ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertFileDoesNotExist($directorio.'/'.$items[0]['imagen']);
    }

    public function testRecursoLibreYBotonParaAgendarUnaCita(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $ana->setUbicacion('Oficina 204')->setEnlaceCita('https://calendar.google.com/ana');
        $guia = self::crearGuia($ana);
        $zotero = (new Recurso())->setTitulo('Zotero')->setUrl('https://www.zotero.org')->setTipo(TipoRecurso::Herramienta)->setAcceso(TipoAcceso::Abierto);
        $scopus = (new Recurso())->setTitulo('Scopus')->setUrl('https://www.scopus.com')->setTipo(TipoRecurso::BaseDatos)->setAcceso(TipoAcceso::Suscripcion);
        self::em()->persist($zotero);
        self::em()->persist($scopus);
        self::em()->flush();
        $seccion = $guia->getPestana()->getSecciones()->first();
        $seccion->agregarBloque((new Bloque(TipoBloque::Recursos))->setContenido(['ids' => [$zotero->getId(), $scopus->getId()], 'descripcion' => false]));
        $seccion->agregarBloque((new Bloque(TipoBloque::Perfil))->setContenido(['usuario' => $ana->getId()]));
        self::em()->flush();

        $crawler = $this->cliente->request('GET', '/es/guias/quimica');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.bloque__recursos .recurso-libre'));
        self::assertStringContainsString('Zotero', $crawler->filter('.recurso-libre')->closest('li')->text());
        self::assertSelectorTextContains('.bloque--perfil .persona__oficina', 'Oficina 204');
        self::assertSame('https://calendar.google.com/ana', $crawler->filter('.bloque--perfil .persona__cita a')->attr('href'));
    }
}
