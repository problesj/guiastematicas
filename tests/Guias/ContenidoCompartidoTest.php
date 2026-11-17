<?php

declare(strict_types=1);

namespace App\Tests\Guias;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Seccion;
use App\Entity\Usuario;
use App\Enum\Rol;
use App\Enum\TipoBloque;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Bloques y pestañas de una guía que se vinculan o copian en otras, y guías de contenido común. */
final class ContenidoCompartidoTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    /** Guía publicada con una pestaña «Inicio» y una sección con un bloque de texto. */
    private static function crearGuia(Usuario $responsable, string $titulo, string $texto, bool $compartible = false): Guia
    {
        $guia = (new Guia())->setTitulo($titulo)->setSlug(strtolower($titulo))->setResponsable($responsable);
        $inicio = new Pestana('Inicio');
        $guia->agregarPestana($inicio);
        $inicio->agregarSeccion((new Seccion('Herramientas'))
            ->agregarBloque((new Bloque(TipoBloque::Texto))->setTitulo('Gestores')->setContenido(['html' => '<p>'.$texto.'</p>'])->setCompartible($compartible)));
        $guia->publicar();
        self::em()->persist($guia);
        self::em()->flush();

        return $guia;
    }

    private function token(Guia $guia): string
    {
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId());
        self::assertResponseIsSuccessful();

        return $crawler->filter('[data-ordenar-token-value]')->attr('data-ordenar-token-value');
    }

    private static function bloque(Guia $guia): Bloque
    {
        return self::em()->find(Guia::class, $guia->getId())->getPestana()->getSeccionesPropias()->first()->getBloques()->first();
    }

    public function testUnBloqueVinculadoMuestraSiempreElOriginalYUnaCopiaEsIndependiente(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl');
        $beto = self::crearUsuario('beto@ucn.cl', nombre: 'Beto');
        $quimica = self::crearGuia($ana, 'Quimica', 'Zotero y Mendeley', compartible: true);
        $fisica = self::crearGuia($beto, 'Fisica', 'Propio de física');
        $original = self::bloque($quimica);
        $seccion = $fisica->getPestana()->getSecciones()->first();
        $this->cliente->loginUser($beto);
        $token = $this->token($fisica);
        $url = '/es/panel/guias/'.$fisica->getId().'/secciones/'.$seccion->getId().'/reutilizar';

        // La biblioteca lista los bloques reutilizables de las otras guías (no los propios ni los no marcados).
        $crawler = $this->cliente->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(['Gestores'], $crawler->filter('.compartido-item strong')->each(fn ($s) => $s->text()));
        self::assertSelectorTextContains('.compartido-item__origen', 'Quimica');
        self::assertCount(0, $this->cliente->request('GET', $url.'?tipo=video')->filter('.compartido-item'));

        $this->cliente->request('POST', $url, ['_token' => $token, 'bloque' => self::bloque($fisica)->getId(), 'modo' => 'vincular']);
        self::assertResponseStatusCodeSame(404); // de la misma guía

        $this->cliente->request('POST', $url, ['_token' => $token, 'bloque' => $original->getId(), 'modo' => 'vincular']);
        self::assertResponseRedirects();
        $this->cliente->request('POST', $url, ['_token' => $token, 'bloque' => $original->getId(), 'modo' => 'copiar']);
        self::em()->clear();
        [$propio, $vinculo, $copia] = self::em()->find(Seccion::class, $seccion->getId())->getBloques()->toArray();
        self::assertSame($original->getId(), $vinculo->getOrigen()->getId());
        self::assertSame([], (new \ReflectionProperty(Bloque::class, 'contenido'))->getValue($vinculo));
        self::assertFalse($copia->isVinculo());
        self::assertSame('Gestores', $copia->getTitulo());

        // Cambia el original: el vínculo lo refleja y la copia no.
        self::em()->find(Bloque::class, $original->getId())->setContenido(['html' => '<p>Zotero, Mendeley y EndNote</p>']);
        self::em()->flush();
        $crawler = $this->cliente->request('GET', '/es/guias/fisica');
        self::assertSame(['Propio de física', 'Zotero, Mendeley y EndNote', 'Zotero y Mendeley'], $crawler->filter('.bloque--texto p')->each(fn ($p) => $p->text()));

        // En el editor el vínculo se identifica y no se edita aquí: Beto no puede editar Química.
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$fisica->getId());
        self::assertSelectorTextContains('#bloque-'.$vinculo->getId().' .insignia', 'Vinculado de Quimica');
        self::assertCount(0, $crawler->filter('#bloque-'.$vinculo->getId().' a[href$="/editar"]'));
        $this->cliente->request('GET', '/es/panel/guias/'.$fisica->getId().'/bloques/'.$vinculo->getId().'/editar');
        self::assertResponseRedirects();

        // Convertir en copia propia.
        $this->cliente->request('POST', '/es/panel/guias/'.$fisica->getId().'/bloques/'.$vinculo->getId().'/desvincular', ['_token' => $token]);
        self::em()->clear();
        $vinculo = self::em()->find(Bloque::class, $vinculo->getId());
        self::assertFalse($vinculo->isVinculo());
        self::assertSame('<p>Zotero, Mendeley y EndNote</p>', $vinculo->dato('html'));

        // Un bloque no marcado como reutilizable no se puede vincular.
        $this->cliente->request('POST', $url, ['_token' => $token, 'bloque' => self::bloque($fisica)->getId() + 100, 'modo' => 'vincular']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testElAutorMarcaElBloqueComoReutilizableYAlEliminarloDesaparecenSusVinculos(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl');
        $quimica = self::crearGuia($ana, 'Quimica', 'Zotero');
        $fisica = self::crearGuia($ana, 'Fisica', 'Propio');
        $original = self::bloque($quimica);
        $this->cliente->loginUser($ana);

        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$quimica->getId().'/bloques/'.$original->getId().'/editar');
        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['bloque[compartible]']->tick();
        $this->cliente->submit($formulario);
        self::assertResponseRedirects();
        self::assertTrue(self::bloque($quimica)->isCompartible());

        $seccion = $fisica->getPestana()->getSecciones()->first();
        $this->cliente->request('POST', '/es/panel/guias/'.$fisica->getId().'/secciones/'.$seccion->getId().'/reutilizar', ['_token' => $this->token($fisica), 'bloque' => $original->getId()]);
        self::assertResponseRedirects();

        // Ana edita el vínculo: la lleva al original.
        $vinculo = self::em()->getRepository(Bloque::class)->findOneBy(['origen' => $original->getId()]);
        $this->cliente->request('GET', '/es/panel/guias/'.$fisica->getId().'/bloques/'.$vinculo->getId().'/editar');
        self::assertResponseRedirects('/es/panel/guias/'.$quimica->getId().'/bloques/'.$original->getId().'/editar');

        // Al eliminar el original se avisa en qué guías está y se quitan los vínculos.
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$quimica->getId());
        self::assertSelectorTextContains('#bloque-'.$original->getId().' .insignia', 'Usado en 1 guía');
        $mensaje = $crawler->filter('#bloque-'.$original->getId().' form[action$="/eliminar"]')->attr('data-confirmar-mensaje-value');
        self::assertStringContainsString('vinculado: Fisica', $mensaje);
        $this->cliente->request('POST', '/es/panel/guias/'.$quimica->getId().'/bloques/'.$original->getId().'/eliminar', ['_token' => $this->token($quimica)]);
        self::em()->clear();
        self::assertCount(1, self::em()->find(Seccion::class, $seccion->getId())->getBloques());
    }

    public function testUnaPestanaVinculadaMuestraLasSeccionesDeLaOriginal(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl');
        $beto = self::crearUsuario('beto@ucn.cl', nombre: 'Beto');
        $quimica = self::crearGuia($ana, 'Quimica', 'Cómo citar en APA');
        $quimica->getPestana()->setCompartible(true);
        self::em()->flush();
        $fisica = self::crearGuia($beto, 'Fisica', 'Propio');
        $this->cliente->loginUser($beto);
        $token = $this->token($fisica);
        $url = '/es/panel/guias/'.$fisica->getId().'/pestanas/reutilizar';

        $crawler = $this->cliente->request('GET', $url);
        self::assertSame(['Inicio'], $crawler->filter('.compartido-item strong')->each(fn ($s) => $s->text()));
        $this->cliente->request('POST', $url, ['_token' => $token, 'pestana' => $quimica->getPestana()->getId(), 'modo' => 'vincular']);
        self::assertResponseRedirects();
        self::em()->clear();
        $vinculada = self::em()->find(Guia::class, $fisica->getId())->getPestanas()->last();
        self::assertTrue($vinculada->isVinculo());
        self::assertSame('inicio-2', $vinculada->getSlug());

        $crawler = $this->cliente->request('GET', '/es/guias/fisica/inicio-2');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bloque--texto', 'Cómo citar en APA');

        // En el editor se muestra sin formularios de secciones y no admite secciones nuevas.
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$fisica->getId().'?pestana='.$vinculada->getId());
        self::assertSelectorTextContains('.aviso--vinculo', '«Inicio» de la guía «Quimica»');
        self::assertCount(0, $crawler->filter('.editor-seccion__titulo'));
        $this->cliente->request('POST', '/es/panel/guias/'.$fisica->getId().'/pestanas/'.$vinculada->getId().'/secciones', ['_token' => $token, 'titulo' => 'Nueva']);
        self::em()->clear();
        self::assertCount(0, self::em()->find(Pestana::class, $vinculada->getId())->getSeccionesPropias());

        // Convertir en copia propia: copia secciones y bloques.
        $this->cliente->request('POST', '/es/panel/guias/'.$fisica->getId().'/pestanas/'.$vinculada->getId().'/desvincular', ['_token' => $token]);
        self::em()->clear();
        $vinculada = self::em()->find(Pestana::class, $vinculada->getId());
        self::assertFalse($vinculada->isVinculo());
        self::assertSame(['Herramientas'], $vinculada->getSeccionesPropias()->map(fn (Seccion $s) => $s->getTitulo())->toArray());
        self::assertSame('<p>Cómo citar en APA</p>', $vinculada->getSecciones()->first()->getBloques()->first()->dato('html'));

        // Copiar directamente también la deja independiente.
        $this->cliente->request('POST', $url, ['_token' => $token, 'pestana' => $quimica->getPestana()->getId(), 'modo' => 'copiar']);
        self::em()->clear();
        $copia = self::em()->find(Guia::class, $fisica->getId())->getPestanas()->last();
        self::assertFalse($copia->isVinculo());
        self::assertCount(1, $copia->getSeccionesPropias());
    }

    public function testLaGuiaDeContenidoComunNoSePublicaYTodoSuContenidoEsReutilizable(): void
    {
        $admin = self::crearUsuario('admin@ucn.cl', Rol::Administrador);
        $beto = self::crearUsuario('beto@ucn.cl', nombre: 'Beto');
        $comun = self::crearGuia($admin, 'Transversal', 'Horario de atención');
        $this->cliente->loginUser($admin);

        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$comun->getId().'/datos');
        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['guia[comun]']->tick();
        $this->cliente->submit($formulario);
        self::assertResponseRedirects();
        self::em()->clear();
        $comun = self::em()->find(Guia::class, $comun->getId());
        self::assertTrue($comun->isComun());
        self::assertFalse($comun->isPublicada());
        $this->cliente->request('GET', '/es/guias/transversal');
        self::assertResponseStatusCodeSame(404);
        self::assertSame($comun, $comun->publicar()); // no cambia nada
        self::assertFalse($comun->isPublicada());

        $fisica = self::crearGuia(self::em()->find(Usuario::class, $beto->getId()), 'Fisica', 'Propio');
        $this->cliente->loginUser($beto);
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$fisica->getId().'/pestanas/reutilizar');
        self::assertSelectorTextContains('.compartido-item', 'Contenido común');
        $seccion = $fisica->getPestana()->getSecciones()->first();
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$fisica->getId().'/secciones/'.$seccion->getId().'/reutilizar');
        self::assertSame(['Gestores'], $crawler->filter('.compartido-item strong')->each(fn ($s) => $s->text()));
    }
}
