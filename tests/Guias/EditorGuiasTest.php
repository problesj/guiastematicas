<?php

declare(strict_types=1);

namespace App\Tests\Guias;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Recurso;
use App\Entity\Seccion;
use App\Entity\Usuario;
use App\Enum\DisposicionGuia;
use App\Enum\EstadoGuia;
use App\Enum\Rol;
use App\Enum\TipoAcceso;
use App\Enum\TipoBloque;
use App\Enum\TipoRecurso;
use App\Servicio\AlmacenImagenesGuia;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditorGuiasTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    /** Guía con dos pestañas; la primera tiene dos secciones (texto + perfil, y una vacía). */
    private static function crearGuia(Usuario $responsable, string $slug = 'quimica', bool $publicada = false): Guia
    {
        $guia = (new Guia())->setTitulo('Química')->setSlug($slug)->setResponsable($responsable);
        $inicio = new Pestana('Inicio');
        $guia->agregarPestana($inicio);
        $inicio->agregarSeccion((new Seccion('Bienvenida'))
            ->agregarBloque((new Bloque(TipoBloque::Texto))->setContenido(['html' => '<p>Hola</p>']))
            ->agregarBloque((new Bloque(TipoBloque::Perfil))->setContenido(['usuario' => $responsable->getId()])));
        $inicio->agregarSeccion(new Seccion('Otra'));
        $guia->agregarPestana(new Pestana('Bases de datos'));
        if ($publicada) {
            $guia->publicar();
        }
        self::em()->persist($guia);
        self::em()->flush();

        return $guia;
    }

    private function token(Guia $guia, string $accion = 'editar'): string
    {
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId());
        self::assertResponseIsSuccessful();
        if ('editar' === $accion) {
            return $crawler->filter('[data-ordenar-token-value]')->attr('data-ordenar-token-value');
        }

        return $crawler->filter('form[action$="/'.$accion.'"] input[name="_token"]')->attr('value');
    }

    private function ordenar(Guia $guia, string $token, array $datos): void
    {
        $this->cliente->request('POST', '/es/panel/guias/'.$guia->getId().'/ordenar', [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token,
        ], json_encode($datos));
    }

    private static function recargar(Guia $guia): Guia
    {
        self::em()->clear();

        return self::em()->find(Guia::class, $guia->getId());
    }

    public function testUnEditorCreaUnaGuiaQueNaceEnBorradorConUnaPestanaYUnaSeccion(): void
    {
        $materia = self::crearMateria('Química', 'quimica');
        $editor = self::crearUsuario('editor@ucn.cl', Rol::Editor);
        $this->cliente->loginUser($editor);

        $crawler = $this->cliente->request('GET', '/es/panel/guias/nueva');
        $formulario = $crawler->selectButton('Crear guía')->form();
        foreach ($formulario['guia[materias]'] as $casilla) {
            if ((string) $materia->getId() === $casilla->availableOptionValues()[0]) {
                $casilla->tick();
            }
        }
        $this->cliente->submit($formulario, [
            'guia[titulo]' => 'Química general: recursos',
            'guia[disposicion]' => 'menu_lateral',
        ]);

        $guia = self::em()->getRepository(Guia::class)->findOneBy(['slug' => 'quimica-general-recursos']);
        self::assertNotNull($guia);
        self::assertResponseRedirects('/es/panel/guias/'.$guia->getId());
        self::assertSame(EstadoGuia::Borrador, $guia->getEstado());
        self::assertSame(DisposicionGuia::MenuLateral, $guia->getDisposicion());
        self::assertSame('es', $guia->getIdioma());
        self::assertSame($editor->getId(), $guia->getResponsable()?->getId());
        self::assertSame(['Inicio'], array_map(fn (Pestana $p) => $p->getTitulo(), $guia->getPestanas()->toArray()));
        self::assertCount(1, $guia->getPestana()->getSecciones());

        $this->cliente->request('GET', '/es/guias/quimica-general-recursos');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnColaboradorNoCreaGuias(): void
    {
        $this->cliente->loginUser(self::crearUsuario('colab@ucn.cl', Rol::Colaborador));
        $this->cliente->request('GET', '/es/panel/guias/nueva');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCadaEditorSoloVeYEditaSusGuiasYElAdministradorTodas(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $bruno = self::crearUsuario('bruno@ucn.cl', Rol::Editor, nombre: 'Bruno');
        $colaborador = self::crearUsuario('colab@ucn.cl', Rol::Colaborador, nombre: 'Carla');
        $guia = self::crearGuia($ana);
        $guia->addCoeditor($colaborador);
        self::em()->flush();

        $this->cliente->loginUser($bruno);
        $crawler = $this->cliente->request('GET', '/es/panel/guias');
        self::assertCount(0, $crawler->filter('tbody tr'));
        $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId());
        self::assertResponseStatusCodeSame(403);

        // El coeditor edita el contenido, pero no los datos ni la publicación.
        $this->cliente->loginUser($colaborador);
        $crawler = $this->cliente->request('GET', '/es/panel/guias');
        self::assertCount(1, $crawler->filter('tbody tr'));
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/publicar"]'));
        $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId().'/datos');
        self::assertResponseStatusCodeSame(403);
        $this->cliente->request('POST', '/es/panel/guias/'.$guia->getId().'/publicar');
        self::assertResponseStatusCodeSame(403);

        $this->cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador, nombre: 'Admin'));
        $crawler = $this->cliente->request('GET', '/es/panel/guias');
        self::assertCount(1, $crawler->filter('tbody tr'));
        $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId().'/datos');
        self::assertResponseIsSuccessful();
    }

    public function testPublicarHaceVisibleLaGuiaConSusPestanasYBloques(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana);
        $this->cliente->loginUser($ana);

        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId().'/vista-previa');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.aviso', 'Vista previa');
        self::assertSelectorTextContains('.bloque--texto', 'Hola');

        $this->cliente->request('POST', '/es/panel/guias/'.$guia->getId().'/publicar', ['_token' => $this->token($guia, 'publicar')]);
        self::assertResponseRedirects('/es/panel/guias/'.$guia->getId());
        self::assertTrue(self::recargar($guia)->isPublicada());

        $crawler = $this->cliente->request('GET', '/es/guias');
        self::assertCount(1, $crawler->filter('.lista-guias__guia:contains("Química")'));
        $crawler = $this->cliente->request('GET', '/es/guias/quimica');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bloque--perfil', 'Ana Pérez');
        self::assertSame(['Inicio', 'Bases de datos'], $crawler->filter('.guia__pestanas a')->each(fn ($a) => $a->text()));
        $this->cliente->request('GET', '/es/guias/quimica/bases-de-datos');
        self::assertResponseIsSuccessful();
        $this->cliente->request('GET', '/es/guias/quimica/no-existe');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, self::recargar($guia)->getVisitas());
    }

    public function testElBloqueDeTextoSeSaneaYElDeRecursosGuardaSoloRecursosExistentes(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana);
        $seccion = $guia->getPestana()->getSecciones()->last();
        $recurso = (new Recurso())->setTitulo('Scopus')->setUrl('https://www.scopus.com')->setTipo(TipoRecurso::BaseDatos)->setAcceso(TipoAcceso::Suscripcion);
        self::em()->persist($recurso);
        self::em()->flush();
        $this->cliente->loginUser($ana);
        $base = '/es/panel/guias/'.$guia->getId().'/secciones/'.$seccion->getId().'/bloques/';

        $crawler = $this->cliente->request('GET', $base.'texto');
        self::assertCount(1, $crawler->filter('textarea[data-controller="editor-texto"]'));
        $this->cliente->submit($crawler->selectButton('Guardar')->form(), [
            'bloque[titulo]' => 'Presentación',
            'bloque[html]' => '<p onclick="x()">Hola <a href="javascript:alert(1)">malo</a> <a href="https://ucn.cl">bueno</a></p><script>alert(1)</script><img src="x">',
        ]);
        self::assertResponseRedirects();

        $crawler = $this->cliente->request('GET', $base.'recursos');
        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['bloque[recursos]']->setValue(json_encode([['id' => $recurso->getId()], ['id' => 999999], ['id' => $recurso->getId()]]));
        $this->cliente->submit($formulario);
        self::assertResponseRedirects();

        $crawler = $this->cliente->request('GET', $base.'video');
        $this->cliente->submit($crawler->selectButton('Guardar')->form(), ['bloque[url]' => 'https://ejemplo.com/video']);
        self::assertResponseStatusCodeSame(422);

        $bloques = self::em()->getRepository(Bloque::class)->findBy(['seccion' => $seccion->getId()], ['posicion' => 'ASC']);
        self::assertCount(2, $bloques);
        $html = $bloques[0]->dato('html');
        self::assertStringNotContainsString('script', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('href="https://ucn.cl"', $html);
        self::assertSame([$recurso->getId()], $bloques[1]->dato('ids'));
        self::assertSame(1, $bloques[1]->getPosicion());
    }

    public function testArrastrarReordenaPestanasYMueveBloquesEntreSecciones(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana);
        $otra = self::crearGuia($ana, 'fisica');
        [$texto, $perfil] = $guia->getPestana()->getSecciones()->first()->getBloques()->toArray();
        [$primera, $segunda] = $guia->getPestana()->getSecciones()->toArray();
        $ajeno = $otra->getPestana()->getSecciones()->first()->getBloques()->first();
        $pestanas = array_map(fn (Pestana $p) => $p->getId(), $guia->getPestanas()->toArray());
        $this->cliente->loginUser($ana);
        $token = $this->token($guia);

        $this->ordenar($guia, 'malo', ['tipo' => 'pestanas', 'ids' => array_reverse($pestanas)]);
        self::assertResponseStatusCodeSame(403);

        $this->ordenar($guia, $token, ['tipo' => 'pestanas', 'ids' => array_reverse($pestanas)]);
        self::assertResponseStatusCodeSame(204);
        $this->ordenar($guia, $token, ['tipo' => 'bloques', 'padre' => $segunda->getId(), 'ids' => [$perfil->getId(), $ajeno->getId()]]);
        self::assertResponseStatusCodeSame(204);
        $this->ordenar($guia, $token, ['tipo' => 'secciones', 'padre' => $pestanas[0], 'ids' => [$segunda->getId(), $primera->getId()]]);
        self::assertResponseStatusCodeSame(204);
        $this->ordenar($guia, $token, ['tipo' => 'bloques', 'padre' => $otra->getPestana()->getSecciones()->first()->getId(), 'ids' => [$texto->getId()]]);
        self::assertResponseStatusCodeSame(400);

        $guia = self::recargar($guia);
        self::assertSame(['Bases de datos', 'Inicio'], array_map(fn (Pestana $p) => $p->getTitulo(), $guia->getPestanas()->toArray()));
        $inicio = $guia->getPestana('inicio');
        self::assertSame(['Otra', 'Bienvenida'], array_map(fn (Seccion $s) => $s->getTitulo(), $inicio->getSecciones()->toArray()));
        self::assertSame([$perfil->getId()], array_map(fn (Bloque $b) => $b->getId(), $inicio->getSecciones()->first()->getBloques()->toArray()));
        self::assertSame([$texto->getId()], array_map(fn (Bloque $b) => $b->getId(), $inicio->getSecciones()->last()->getBloques()->toArray()));
        self::assertSame($otra->getId(), self::em()->find(Bloque::class, $ajeno->getId())->getGuia()?->getId());
    }

    public function testMoverUnBloqueAOtraPestanaOAOtraGuiaQueSePuedaEditar(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $luis = self::crearUsuario('luis@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana);
        $otra = self::crearGuia($ana, 'fisica');
        $ajena = self::crearGuia($luis, 'historia');
        [$texto, $perfil] = $guia->getPestana()->getSecciones()->first()->getBloques()->toArray();
        $bases = $guia->getPestana('bases-de-datos');
        $destinoOtra = $otra->getPestana()->getSecciones()->first();
        $diapositivas = (new Bloque(TipoBloque::Diapositivas))->setContenido(['items' => [['titulo' => 'Uno', 'imagen' => 'd'.$guia->getId().'-'.str_repeat('a', 24).'.png']]]);
        $guia->getPestana()->getSecciones()->last()->agregarBloque($diapositivas);
        self::em()->flush();
        $directorio = static::getContainer()->getParameter('kernel.project_dir').'/public/'.AlmacenImagenesGuia::RUTA_PUBLICA;
        @mkdir($directorio, 0o777, true);
        file_put_contents($directorio.'/d'.$guia->getId().'-'.str_repeat('a', 24).'.png', 'png');
        $this->cliente->loginUser($ana);
        $mover = fn (Bloque $b, array $datos) => $this->cliente->request('POST', '/es/panel/guias/'.$guia->getId().'/bloques/'.$b->getId().'/mover', $datos);

        // El formulario ofrece las secciones de las guías que Ana edita (no la de Luis) y una sección nueva por pestaña.
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId().'/bloques/'.$texto->getId().'/mover');
        self::assertResponseIsSuccessful();
        $valores = $crawler->filter('#destino option')->each(fn ($o) => $o->attr('value'));
        self::assertContains('s'.$destinoOtra->getId(), $valores);
        self::assertContains('p'.$bases->getId(), $valores);
        self::assertNotContains('s'.$ajena->getPestana()->getSecciones()->first()->getId(), $valores);
        self::assertStringStartsWith('Química (esta guía)', $crawler->filter('#destino optgroup')->first()->attr('label'));
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        // Token inválido o guía ajena: no se mueve.
        $mover($texto, ['_token' => 'malo', 'destino' => 'p'.$bases->getId()]);
        self::assertResponseStatusCodeSame(403);
        $mover($texto, ['_token' => $token, 'destino' => 's'.$ajena->getPestana()->getSecciones()->first()->getId()]);
        self::assertResponseRedirects('/es/panel/guias/'.$guia->getId().'/bloques/'.$texto->getId().'/mover');

        // A otra pestaña de la misma guía, en una sección nueva.
        $mover($texto, ['_token' => $token, 'destino' => 'p'.$bases->getId(), 'posicion' => 'final']);
        self::assertResponseRedirects('/es/panel/guias/'.$guia->getId().'?pestana='.$bases->getId().'#bloque-'.$texto->getId());

        // A otra guía, al principio de una sección que ya tiene bloques; las diapositivas se llevan sus imágenes.
        $mover($perfil, ['_token' => $token, 'destino' => 's'.$destinoOtra->getId(), 'posicion' => 'inicio']);
        self::assertResponseRedirects('/es/panel/guias/'.$otra->getId().'?pestana='.$otra->getPestana()->getId().'#bloque-'.$perfil->getId());
        $mover($diapositivas, ['_token' => $token, 'destino' => 's'.$destinoOtra->getId()]);

        $guia = self::recargar($guia);
        $otra = self::em()->find(Guia::class, $otra->getId());
        self::assertSame([], $guia->getPestana()->getSecciones()->first()->getBloques()->toArray());
        $secciones = $guia->getPestana('bases-de-datos')->getSecciones();
        self::assertCount(1, $secciones);
        self::assertSame([$texto->getId()], array_map(fn (Bloque $b) => $b->getId(), $secciones->first()->getBloques()->toArray()));
        $bloquesOtra = $otra->getPestana()->getSecciones()->first()->getBloques()->toArray();
        self::assertCount(4, $bloquesOtra);
        self::assertSame($perfil->getId(), $bloquesOtra[0]->getId());
        self::assertSame($diapositivas->getId(), $bloquesOtra[3]->getId());
        self::assertSame([0, 1, 2, 3], array_map(fn (Bloque $b) => $b->getPosicion(), $bloquesOtra));
        $imagen = $bloquesOtra[3]->dato('items')[0]['imagen'];
        self::assertSame('d'.$otra->getId().'-'.str_repeat('a', 24).'.png', $imagen);
        self::assertFileExists($directorio.'/'.$imagen);
        self::assertFileDoesNotExist($directorio.'/d'.$guia->getId().'-'.str_repeat('a', 24).'.png');
        @unlink($directorio.'/'.$imagen);
    }

    public function testPestanasYSeccionesSeAgreganRenombranYEliminan(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana);
        $this->cliente->loginUser($ana);
        $token = $this->token($guia);
        $id = $guia->getId();

        $this->cliente->request('POST', "/es/panel/guias/$id/pestanas", ['_token' => $token, 'titulo' => 'Inicio']);
        self::assertResponseRedirects();
        $guia = self::recargar($guia);
        $nueva = $guia->getPestanas()->last();
        self::assertSame('inicio-2', $nueva->getSlug());

        $this->cliente->request('POST', "/es/panel/guias/$id/pestanas/{$nueva->getId()}/renombrar", ['_token' => $token, 'titulo' => 'Cómo citar']);
        $this->cliente->request('POST', "/es/panel/guias/$id/pestanas/{$nueva->getId()}/secciones", ['_token' => $token, 'titulo' => 'APA']);
        $guia = self::recargar($guia);
        self::assertSame('como-citar', $guia->getPestanas()->last()->getSlug());
        self::assertSame([null, 'APA'], array_map(fn (Seccion $s) => $s->getTitulo(), $guia->getPestanas()->last()->getSecciones()->toArray()));

        $this->cliente->request('POST', "/es/panel/guias/$id/pestanas/{$nueva->getId()}/eliminar", ['_token' => $token]);
        self::assertResponseRedirects("/es/panel/guias/$id");
        self::assertCount(2, self::recargar($guia)->getPestanas());

        // Una pestaña de otra guía no se puede tocar desde esta.
        $otra = self::crearGuia(self::em()->find(Usuario::class, $ana->getId()), 'fisica');
        $this->cliente->request('POST', "/es/panel/guias/$id/pestanas/{$otra->getPestana()->getId()}/eliminar", ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testLaTraduccionPublicadaEnlazaLosIdiomasYElBuscadorVaAPrimo(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana, publicada: true);
        $ingles = (new Guia())->setTitulo('Chemistry')->setSlug('chemistry')->setIdioma('en')->setResponsable($ana)->publicar();
        $ingles->agregarPestana(new Pestana('Home'));
        $ingles->setTraduccion($guia);
        self::em()->persist($ingles);
        self::em()->flush();

        $crawler = $this->cliente->request('GET', '/es/guias/quimica');
        self::assertSame('/en/guias/chemistry', $crawler->filter('.idiomas a[hreflang="en"], .idiomas a:contains("EN")')->attr('href'));

        $this->cliente->request('GET', '/es/catalogo?q='.rawurlencode('agua potable'));
        $destino = $this->cliente->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://primo.ejemplo.cl/discovery/search?query=any,contains,agua%20potable', $destino);
        self::assertStringContainsString('vid=56UCN_INST:UCN', $destino);
        self::assertStringContainsString('lang=es', $destino);

        $this->cliente->request('GET', '/es/catalogo?q=');
        self::assertResponseRedirects('/es/guias');
    }

    public function testEliminarLaGuiaExigeSerResponsableOAdministrador(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana);
        $id = $guia->getId();
        $this->cliente->loginUser($ana);
        $crawler = $this->cliente->request('GET', "/es/panel/guias/$id/datos");
        $token = $crawler->filter('form[action$="/eliminar"] input[name="_token"]')->attr('value');

        $this->cliente->request('POST', "/es/panel/guias/$id/eliminar", ['_token' => $token]);
        self::assertResponseRedirects('/es/panel/guias');
        self::em()->clear();
        self::assertNull(self::em()->find(Guia::class, $id));
        self::assertSame(0, self::em()->getRepository(Bloque::class)->count([]));
    }

    public function testLaGuiaLlevaBannerEIconoQueSeMuestranYSePuedenQuitar(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana, publicada: true);
        $this->cliente->loginUser($ana);
        $png = sys_get_temp_dir().'/guia-prueba.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $texto = sys_get_temp_dir().'/no-es-imagen.png';
        file_put_contents($texto, 'hola');

        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId().'/datos');
        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['guia[archivoBanner]']->upload($texto);
        $this->cliente->submit($formulario);
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId().'/datos');
        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['guia[archivoBanner]']->upload($png);
        $formulario['guia[archivoIcono]']->upload($png);
        $this->cliente->submit($formulario);
        self::assertResponseRedirects();
        $guia = self::recargar($guia);
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}\.png$/', (string) $guia->getBanner());
        self::assertNotNull($guia->getIcono());
        $directorio = static::getContainer()->getParameter('kernel.project_dir').'/public/'.AlmacenImagenesGuia::RUTA_PUBLICA;
        self::assertFileExists($directorio.'/'.$guia->getBanner());

        $crawler = $this->cliente->request('GET', '/es/guias/quimica');
        self::assertSame('/uploads/guias/'.$guia->getBanner(), $crawler->filter('img.guia__banner')->attr('src'));
        self::assertCount(1, $crawler->filter('img.guia__icono'));
        $crawler = $this->cliente->request('GET', '/es/guias');
        self::assertSame('/uploads/guias/'.$guia->getIcono(), $crawler->filter('img.lista-guias__icono')->attr('src'));

        $banner = $guia->getBanner();
        $icono = $guia->getIcono();
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId().'/datos');
        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['guia[eliminarBanner]']->tick();
        $this->cliente->submit($formulario);
        self::assertResponseRedirects();
        $guia = self::recargar($guia);
        self::assertNull($guia->getBanner());
        self::assertSame($icono, $guia->getIcono());
        self::assertFileDoesNotExist($directorio.'/'.$banner);

        static::getContainer()->get(AlmacenImagenesGuia::class)->eliminarDe($guia);
        self::assertFileDoesNotExist($directorio.'/'.$icono);
    }

    public function testElCatalogoDelBloqueFiltraRecursosPorTextoTipoYMateria(): void
    {
        $quimica = self::crearMateria('Química', 'quimica');
        $fisica = self::crearMateria('Física', 'fisica');
        $crear = function (string $titulo, TipoRecurso $tipo, array $materias) {
            $recurso = (new Recurso())->setTitulo($titulo)->setUrl('https://ejemplo.org/'.md5($titulo))->setTipo($tipo)->setAcceso(TipoAcceso::Abierto)->setProveedor('Proveedor X');
            foreach ($materias as $materia) {
                $recurso->addMateria($materia);
            }
            self::em()->persist($recurso);
        };
        $crear('Reaxys', TipoRecurso::BaseDatos, [$quimica]);
        $crear('SciFinder', TipoRecurso::BaseDatos, [$quimica, $fisica]);
        $crear('Journal of Chemistry', TipoRecurso::Revistas, [$quimica]);
        $crear('Physics Today', TipoRecurso::Revistas, [$fisica]);
        self::em()->flush();
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $this->cliente->loginUser($ana);

        $buscar = function (array $filtros): array {
            $this->cliente->request('GET', '/es/panel/guias/buscar-recursos?'.http_build_query($filtros));
            self::assertResponseIsSuccessful();

            return json_decode($this->cliente->getResponse()->getContent(), true);
        };
        $titulos = fn (array $r) => array_column($r['elementos'], 'nombre');

        self::assertSame(4, $buscar([])['total']);
        self::assertSame(4, $buscar(['q' => '', 'tipo' => '', 'materia' => '', 'pagina' => '1'])['total']);
        self::assertSame(['Reaxys', 'SciFinder'], $titulos($buscar(['tipo' => 'base_datos'])));
        self::assertSame(['Physics Today', 'SciFinder'], $titulos($buscar(['materia' => $fisica->getId()])));
        self::assertSame(['Journal of Chemistry'], $titulos($buscar(['materia' => $quimica->getId(), 'tipo' => 'revistas'])));
        self::assertSame(['SciFinder'], $titulos($buscar(['q' => 'scif', 'materia' => $quimica->getId()])));
        self::assertSame(4, $buscar(['tipo' => 'no-existe'])['total']);
        self::assertSame('Base de datos · Proveedor X', $buscar(['q' => 'Reaxys'])['elementos'][0]['detalle']);

        $guia = self::crearGuia(self::em()->find(Usuario::class, $ana->getId()));
        $seccion = $guia->getPestana()->getSecciones()->first();
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId().'/secciones/'.$seccion->getId().'/bloques/recursos');
        self::assertResponseIsSuccessful();
        self::assertCount(\count(TipoRecurso::cases()) + 1, $crawler->filter('#catalogo-tipo option'));
        self::assertSame(['Todas las materias', 'Física', 'Química'], $crawler->filter('#catalogo-materia option')->each(fn ($o) => $o->text()));
    }

    public function testSubpestanasEnlacesExternosColumnasYNavegacionDeLaGuia(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana, publicada: true);
        $guia->addMateria(self::crearMateria('Química', 'quimica'))->addMateria(self::crearMateria('Física', 'fisica'));
        self::em()->flush();
        $this->cliente->loginUser($ana);
        $token = $this->token($guia);
        $id = $guia->getId();
        [$inicio, $bases] = $guia->getPestanas()->toArray();
        $opciones = fn (Pestana $p, array $datos) => $this->cliente->request('POST', "/es/panel/guias/$id/pestanas/{$p->getId()}/renombrar", ['_token' => $token, 'titulo' => $p->getTitulo()] + $datos);

        // Nueva pestaña "Normas APA" como subpestaña de "Bases de datos", marcada como nueva.
        $this->cliente->request('POST', "/es/panel/guias/$id/pestanas", ['_token' => $token, 'titulo' => 'Normas APA']);
        $apa = self::recargar($guia)->getPestanas()->last();
        $opciones($apa, ['padre' => (string) $bases->getId(), 'nueva' => '1']);
        $apa = self::em()->find(Pestana::class, $apa->getId());
        self::assertSame($bases->getId(), $apa->getPadre()?->getId());
        self::assertTrue($apa->isNueva());

        // Solo un nivel: "Bases de datos" tiene hijas y no puede colgar de "Inicio"; tampoco de sí misma.
        $opciones($bases, ['padre' => (string) $inicio->getId()]);
        self::assertNull(self::em()->find(Pestana::class, $bases->getId())->getPadre());
        $opciones($inicio, ['padre' => (string) $inicio->getId()]);
        self::assertNull(self::em()->find(Pestana::class, $inicio->getId())->getPadre());

        // Pestaña que es un enlace externo; un enlace no válido se rechaza.
        $this->cliente->request('POST', "/es/panel/guias/$id/pestanas", ['_token' => $token, 'titulo' => 'Aprende']);
        $aprende = self::recargar($guia)->getPestanas()->last();
        $opciones($aprende, ['enlace' => 'javascript:alert(1)']);
        self::assertNull(self::em()->find(Pestana::class, $aprende->getId())->getEnlace());
        $opciones($aprende, ['enlace' => 'https://bibliotecas.ucn.cl/aprende']);
        self::assertSame('https://bibliotecas.ucn.cl/aprende', self::em()->find(Pestana::class, $aprende->getId())->getEnlace());

        // Columnas de la sección (se limitan a 1..3).
        $seccion = $inicio->getSecciones()->first();
        $this->cliente->request('POST', "/es/panel/guias/$id/secciones/{$seccion->getId()}/renombrar", ['_token' => $token, 'titulo' => 'Bienvenida', 'columnas' => '2']);
        self::assertSame(2, self::em()->find(Seccion::class, $seccion->getId())->getColumnas());
        $this->cliente->request('POST', "/es/panel/guias/$id/secciones/{$seccion->getId()}/renombrar", ['_token' => $token, 'titulo' => 'Bienvenida', 'columnas' => '9']);
        self::assertSame(3, self::em()->find(Seccion::class, $seccion->getId())->getColumnas());

        // El editor muestra la subpestaña bajo su principal.
        $crawler = $this->cliente->request('GET', "/es/panel/guias/$id");
        self::assertSame(['Inicio', 'Bases de datos', 'Normas APA', 'Aprende'], $crawler->filter('.editor-guia__lista-pestanas li > a')->each(fn ($a) => $a->filter('.solo-lectores')->count() ? trim(str_replace($a->filter('.solo-lectores')->text(), '', $a->text())) : $a->text()));
        self::assertCount(1, $crawler->filter('.editor-guia__subpestana'));

        // Vista pública: menú con subpestañas, enlace externo, columnas, Anterior / Siguiente, migas y pie.
        $crawler = $this->cliente->request('GET', '/es/guias/quimica');
        self::assertCount(1, $crawler->filter('.guia__pestana--con-hijas .guia__subpestanas a[href="/es/guias/quimica/normas-apa"]'));
        self::assertSelectorTextContains('.guia__subpestanas .insignia--nuevo', 'Nuevo');
        $externa = $crawler->filter('.guia__pestanas a[href="https://bibliotecas.ucn.cl/aprende"]');
        self::assertSame('_blank', $externa->attr('target'));
        self::assertCount(1, $crawler->filter('.guia__cajas--3 .bloque--texto'));
        self::assertCount(0, $crawler->filter('.guia__anterior'));
        self::assertSame('/es/guias/quimica/bases-de-datos', $crawler->filter('.guia__siguiente')->attr('href'));
        self::assertSelectorTextContains('.guia__pie', 'Última actualización');
        self::assertStringStartsWith('mailto:ana@ucn.cl?subject=', $crawler->filter('.guia__pie a[href^="mailto:"]')->attr('href'));

        $crawler = $this->cliente->request('GET', '/es/guias/quimica/normas-apa');
        self::assertResponseIsSuccessful();
        self::assertSame('/es/guias/quimica/bases-de-datos', $crawler->filter('.guia__anterior')->attr('href'));
        self::assertCount(0, $crawler->filter('.guia__siguiente')); // "Aprende" es externa: no entra en el recorrido
        self::assertSelectorTextContains('.guia__pie', 'Física');
        self::assertSame(['Bibliotecas UCN', 'Guías por materia', 'Física', 'Química', 'Normas APA'], array_map('trim', explode('›', $crawler->filter('.migas')->text())));
        self::assertSelectorTextContains('h2.guia__titulo-pestana', 'Normas APA');

        $this->cliente->request('GET', '/es/guias/quimica/aprende');
        self::assertResponseRedirects('https://bibliotecas.ucn.cl/aprende');

        // Al eliminar la principal, su subpestaña pasa a ser principal.
        $this->cliente->request('POST', "/es/panel/guias/$id/pestanas/{$bases->getId()}/eliminar", ['_token' => $token]);
        self::assertTrue(self::em()->find(Pestana::class, $apa->getId())->isPrincipal());
    }
}
