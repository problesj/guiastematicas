<?php

declare(strict_types=1);

namespace App\Tests\Guias;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Pestana;
use App\Entity\Recurso;
use App\Entity\Seccion;
use App\Enum\Rol;
use App\Enum\TipoAcceso;
use App\Enum\TipoBloque;
use App\Enum\TipoRecurso;
use App\Tests\CatalogoFalso;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Asistente que arma una guía con los recursos de sus materias, Biblioges, el contenido transversal y Gemini. */
final class AsistenteGuiasTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        CatalogoFalso::$gemini = null;

        $quimica = self::crearMateria('Química', 'quimica');
        $historia = self::crearMateria('Historia', 'historia');
        foreach ([
            ['SciFinder', TipoRecurso::BaseDatos, $quimica],
            ['Reaxys', TipoRecurso::BaseDatos, $quimica],
            ['JSTOR', TipoRecurso::BaseDatos, $historia],
            ['Journal of Chemical Education', TipoRecurso::Revistas, $quimica],
            ['PubChem', TipoRecurso::SitioWeb, $quimica],
            ['Química orgánica (Wade)', TipoRecurso::Libros, $quimica],
        ] as [$titulo, $tipo, $materia]) {
            $recurso = (new Recurso())->setTitulo($titulo)->setUrl('https://ejemplo.cl/'.rawurlencode($titulo))->setTipo($tipo)->setAcceso(TipoAcceso::Suscripcion)->addMateria($materia);
            self::em()->persist($recurso);
            self::em()->flush();
            $this->ids[$titulo] = $recurso->getId();
        }

        // Guía de contenido común con las bases multidisciplinarias y la pestaña de citación.
        $admin = self::crearUsuario('admin@ucn.cl', Rol::Administrador, nombre: 'Admin');
        $comun = (new Guia())->setTitulo('Contenido común')->setSlug('comun')->setResponsable($admin)->setComun(true);
        $comun->agregarPestana((new Pestana('Inicio'))->agregarSeccion((new Seccion('Multi'))
            ->agregarBloque((new Bloque(TipoBloque::Recursos))->setTitulo('Multidisciplinarias')->setContenido(['ids' => [$this->ids['JSTOR']]]))));
        $comun->agregarPestana((new Pestana('Cómo citar'))->agregarSeccion((new Seccion('APA'))->agregarBloque((new Bloque(TipoBloque::Texto))->setContenido(['html' => '<p>APA 7</p>']))));
        self::em()->persist($comun);
        self::em()->flush();
        $this->ids['multi'] = $comun->getPestana()->getSecciones()->first()->getBloques()->first()->getId();
        $this->ids['citar'] = $comun->getPestanas()->last()->getId();
        $this->ids['quimica'] = $quimica->getId();
    }

    private function configurarTransversal(): void
    {
        $this->cliente->loginUser(self::em()->getRepository(\App\Entity\Usuario::class)->findOneBy(['email' => 'admin@ucn.cl']));
        $crawler = $this->cliente->request('GET', '/es/panel/guias/asistente');
        self::assertResponseIsSuccessful();
        $this->cliente->submit($crawler->filter('form[action$="/transversal"]')->form(), ['bloque' => $this->ids['multi'], 'pestana' => $this->ids['citar']]);
        self::assertResponseRedirects('/es/panel/guias/asistente');
    }

    /** @param array<string, mixed> $valores */
    private function crearGuia(array $valores): Guia
    {
        $this->cliente->loginUser(self::crearUsuario('ana@ucn.cl', Rol::Editor));
        $crawler = $this->cliente->request('GET', '/es/panel/guias/asistente');
        $formulario = $crawler->selectButton('Crear la guía')->form();
        $formulario['asistente_guia[titulo]'] = 'Química general';
        foreach ($formulario['asistente_guia[materias]'] as $casilla) {
            if ($casilla->availableOptionValues() === [(string) $this->ids['quimica']]) {
                $casilla->tick();
            }
        }
        foreach ($valores as $campo => $valor) {
            if (false === $valor) {
                $formulario[$campo]->untick();
            } elseif (\is_array($valor)) {
                $formulario[$campo]->select($valor);
            } else {
                $formulario[$campo] = $valor;
            }
        }
        $this->cliente->submit($formulario);
        self::assertResponseRedirects();
        self::em()->clear();

        return self::em()->getRepository(Guia::class)->findOneBy(['titulo' => 'Química general']);
    }

    /** @return array<string, list<Bloque>> bloques por título de pestaña */
    private static function bloques(Guia $guia): array
    {
        $resultado = [];
        foreach ($guia->getPestanas() as $pestana) {
            foreach ($pestana->getSecciones() as $seccion) {
                foreach ($seccion->getBloques() as $bloque) {
                    $resultado[$pestana->getTitulo()][] = $bloque;
                }
            }
        }

        return $resultado;
    }

    public function testConIaArmaTodaLaGuiaConRecursosDelCatalogoYSitiosQueResponden(): void
    {
        $this->configurarTransversal();
        $guia = $this->crearGuia(['asistente_guia[carreras]' => ['8606']]);

        self::assertFalse($guia->isPublicada());
        self::assertSame(['Química'], $guia->getMaterias()->map(fn (Materia $m) => $m->getNombre())->toArray());
        self::assertSame(['Presentación', 'Bases de datos', 'Revistas', 'Sitios web', 'Citación y gestores', 'Libros'], $guia->getPestanas()->map(fn (Pestana $p) => $p->getTitulo())->toArray());
        $bloques = self::bloques($guia);

        [$bienvenida, $perfil] = $bloques['Presentación'];
        self::assertSame('<p>Bienvenida generada para Química general.</p><p>Segundo párrafo &lt;b&gt;sin html&lt;/b&gt;.</p>', $bienvenida->dato('html'));
        self::assertSame(TipoBloque::Perfil, $perfil->getTipo());

        // Gemini eligió el último candidato (y un id inventado, que se descarta).
        [$intro, $bases, $multi] = $bloques['Bases de datos'];
        self::assertSame('<p>Intro de bases.</p>', $intro->dato('html'));
        self::assertSame([$this->ids['SciFinder']], $bases->dato('ids'));
        self::assertSame($this->ids['multi'], $multi->getOrigen()->getId());

        // Sitios: el de la lista A-Z y, aparte, solo el sugerido que responde.
        [, $sitios, $sugeridos] = $bloques['Sitios web'];
        self::assertSame([$this->ids['PubChem']], $sitios->dato('ids'));
        self::assertSame(TipoBloque::Enlaces, $sugeridos->getTipo());
        self::assertSame('Sugeridos por IA (revisar)', $sugeridos->getTitulo());
        self::assertSame(['https://www.sitio-real.ejemplo.cl/'], array_column($sugeridos->dato('items'), 'url'));

        self::assertTrue($guia->getPestanas()->get(4)->isVinculo());
        [, $libros, $bibliografia] = $bloques['Libros'];
        self::assertSame([$this->ids['Química orgánica (Wade)']], $libros->dato('ids'));
        self::assertSame(['Química (e-book)'], array_column($bibliografia->dato('items'), 'titulo'));
        self::assertNotNull($bibliografia->dato('items')[0]['recurso']); // incorporado a la lista A-Z

        $this->cliente->followRedirects();
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId());
        self::assertResponseIsSuccessful();
    }

    public function testSinIaUsaTodosLosRecursosDeLasMateriasEnOrdenAlfabetico(): void
    {
        $guia = $this->crearGuia(['asistente_guia[usar_ia]' => false]);
        $bloques = self::bloques($guia);
        [$bienvenida] = $bloques['Presentación'];
        self::assertStringContainsString('Bienvenido/a a la guía Química general', $bienvenida->dato('html'));
        [$bases] = $bloques['Bases de datos'];
        self::assertSame([$this->ids['Reaxys'], $this->ids['SciFinder']], $bases->dato('ids'));
        self::assertSame('titulo', $bases->dato('orden'));
        // Sin contenido transversal elegido: no hay pestaña de citación ni bloque multidisciplinario.
        self::assertSame(['Presentación', 'Bases de datos', 'Revistas', 'Sitios web', 'Libros'], array_keys($bloques));
        self::assertCount(1, $bloques['Bases de datos']);
        self::assertCount(1, $bloques['Sitios web']);
        self::assertContains('asistente.aviso_sin_multidisciplinarias', $this->cliente->getRequest()->getSession()->getFlashBag()->peekAll()['aviso'] ?? []);
    }

    public function testSiGeminiFallaLaGuiaSeArmaIgualYSeAvisa(): void
    {
        CatalogoFalso::$gemini = 'error';
        $guia = $this->crearGuia([]);
        [$bases] = self::bloques($guia)['Bases de datos'];
        self::assertSame([$this->ids['Reaxys'], $this->ids['SciFinder']], $bases->dato('ids'));
        $crawler = $this->cliente->followRedirect();
        self::assertSelectorTextContains('.aviso--aviso', 'Gemini no respondió');
    }

    public function testExigeAlMenosUnaMateriaYSoloElAdministradorEligeElContenidoTransversal(): void
    {
        $this->cliente->loginUser(self::crearUsuario('ana@ucn.cl', Rol::Editor));
        $crawler = $this->cliente->request('GET', '/es/panel/guias/asistente');
        self::assertCount(0, $crawler->filter('form[action$="/transversal"]'));
        $this->cliente->submit($crawler->selectButton('Crear la guía')->form(), ['asistente_guia[titulo]' => 'Sin materias']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.formulario', 'Elige al menos una materia');
        $this->cliente->request('POST', '/es/panel/guias/asistente/transversal', ['bloque' => '1']);
        self::assertResponseStatusCodeSame(403);
    }
}
