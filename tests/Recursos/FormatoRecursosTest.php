<?php

declare(strict_types=1);

namespace App\Tests\Recursos;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Recurso;
use App\Entity\Seccion;
use App\Entity\Sugerencia;
use App\Enum\FormatoRecurso;
use App\Enum\Rol;
use App\Enum\TipoRecurso;
use App\Servicio\Importacion\ImportadorRecursos;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Libros y revistas indican si están impresos, en formato electrónico o en ambos. */
final class FormatoRecursosTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    private static function recurso(string $titulo, TipoRecurso $tipo, ?FormatoRecurso $formato = null): Recurso
    {
        $recurso = (new Recurso())->setTitulo($titulo)->setUrl('https://ejemplo.cl/'.rawurlencode($titulo))->setTipo($tipo)->setFormato($formato);
        self::em()->persist($recurso);

        return $recurso;
    }

    public function testSoloLibrosYRevistasTienenFormatoYPorDefectoEsElectronico(): void
    {
        $libro = (new Recurso())->setTipo(TipoRecurso::Libros);
        self::assertSame(FormatoRecurso::Electronico, $libro->getFormato());
        $libro->setFormato(FormatoRecurso::Impreso)->setTipo(TipoRecurso::BaseDatos)->limpiarCamposNoAplicables();
        self::assertNull($libro->getFormato());
        $libro->setTipo(TipoRecurso::Revistas);
        self::assertSame(FormatoRecurso::Electronico, $libro->getFormato());

        self::assertSame([FormatoRecurso::Impreso, FormatoRecurso::Ambos], FormatoRecurso::Impreso->coincidentes());
        self::assertSame(FormatoRecurso::Ambos, FormatoRecurso::desdeCategoriasPrimo(['Alma-P', 'Alma-E']));
        self::assertSame(FormatoRecurso::Impreso, FormatoRecurso::desdeCategoriasPrimo(['Alma-P']));
        self::assertSame(FormatoRecurso::Electronico, FormatoRecurso::desdeCategoriasPrimo(['Remote Search Resource']));
        self::assertNull(FormatoRecurso::desdeCategoriasPrimo([]));

        self::assertSame(FormatoRecurso::Impreso, ImportadorRecursos::interpretarFormato('Papel'));
        self::assertSame(FormatoRecurso::Electronico, ImportadorRecursos::interpretarFormato('En línea'));
        self::assertSame(FormatoRecurso::Ambos, ImportadorRecursos::interpretarFormato('Impreso y electrónico'));
        self::assertNull(ImportadorRecursos::interpretarFormato('Base de datos'));
    }

    public function testLaListaPublicaMuestraYFiltraElFormato(): void
    {
        self::recurso('Química general', TipoRecurso::Libros, FormatoRecurso::Impreso);
        self::recurso('Cálculo', TipoRecurso::Libros, FormatoRecurso::Ambos);
        self::recurso('Física moderna', TipoRecurso::Libros, FormatoRecurso::Electronico);
        self::recurso('Scopus', TipoRecurso::BaseDatos);
        self::em()->flush();

        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos');
        self::assertResponseIsSuccessful();
        // El tipo se llama solo «Libros»; el formato va aparte.
        self::assertSame('Libros', trim($crawler->filter('#tipo option[value="libros"]')->text()));
        $formatos = $crawler->filter('.recurso')->each(fn ($r) => [$r->filter('.recurso__titulo a')->text(), $r->filter('.formato')->count() ? trim($r->filter('.formato')->text()) : null]);
        self::assertContains(['Química general', 'Impreso'], $formatos);
        self::assertContains(['Cálculo', 'Impreso y electrónico'], $formatos);
        self::assertContains(['Física moderna', 'Electrónico'], $formatos);
        self::assertContains(['Scopus', null], $formatos);

        // «Impreso» incluye los que están en ambos formatos.
        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?formato=impreso');
        self::assertSame(['Cálculo', 'Química general'], $crawler->filter('.recurso__titulo a')->each(fn ($a) => $a->text()));
        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?formato=ambos');
        self::assertSame(['Cálculo'], $crawler->filter('.recurso__titulo a')->each(fn ($a) => $a->text()));
    }

    public function testElPanelGuardaYFiltraElFormato(): void
    {
        $editor = self::crearUsuario('editor@ucn.cl', Rol::Editor);
        self::recurso('Scopus', TipoRecurso::BaseDatos);
        self::em()->flush();
        $this->cliente->loginUser($editor);

        $this->cliente->request('GET', '/es/panel/recursos/nuevo');
        $this->cliente->submitForm('Guardar', [
            'recurso[titulo]' => 'Anatomía de Gray',
            'recurso[url]' => 'https://catalogo.ucn.cl/gray',
            'recurso[tipo]' => 'libros',
            'recurso[formato]' => 'impreso',
            'recurso[acceso]' => 'suscripcion',
        ]);
        self::assertResponseRedirects('/es/panel/recursos');
        $gray = self::em()->getRepository(Recurso::class)->findOneBy(['titulo' => 'Anatomía de Gray']);
        self::assertSame(FormatoRecurso::Impreso, $gray->getFormato());

        // Un tipo sin formato lo descarta al guardar.
        $this->cliente->request('GET', '/es/panel/recursos/'.$gray->getId().'/editar');
        $this->cliente->submitForm('Guardar', ['recurso[tipo]' => 'sitio_web']);
        self::em()->clear();
        self::assertNull(self::em()->getConnection()->fetchOne('SELECT formato FROM recurso WHERE id = ?', [$gray->getId()]));

        self::recurso('Lancet', TipoRecurso::Revistas, FormatoRecurso::Ambos);
        self::em()->flush();
        $crawler = $this->cliente->request('GET', '/es/panel/recursos?formato=electronico');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringStartsWith('Lancet', $crawler->filter('tbody td')->first()->text());
        self::assertSelectorTextContains('tbody .formato', 'Impreso y electrónico');

        // Buscador de recursos del bloque Recursos de una guía.
        $this->cliente->request('GET', '/es/panel/guias/buscar-recursos?formato=impreso');
        $respuesta = json_decode((string) $this->cliente->getResponse()->getContent(), true);
        self::assertSame(['Lancet'], array_column($respuesta['elementos'], 'nombre'));
        self::assertStringContainsString('Impreso y electrónico', $respuesta['elementos'][0]['detalle']);
    }

    public function testLosLibrosDelBloqueLlevanSuFormatoALaLista(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = (new Guia())->setTitulo('Química')->setSlug('quimica')->setResponsable($ana);
        $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion(new Seccion('Libros')));
        self::em()->persist($guia);
        self::em()->flush();
        $seccion = $guia->getPestana()->getSecciones()->first();
        $this->cliente->loginUser($ana);

        $url = '/es/panel/guias/'.$guia->getId().'/secciones/'.$seccion->getId().'/bloques/libros';
        $crawler = $this->cliente->request('GET', $url);
        $token = $crawler->filter('input[name="bloque[_token]"]')->attr('value');
        $this->cliente->request('POST', $url, ['bloque' => ['titulo' => 'Libros', 'isbns' => '', '_token' => $token, 'items' => [
            ['isbn' => '978-0-262-03384-8'], // el catálogo lo informa como físico
            ['titulo' => 'Apuntes en línea', 'enlace' => 'https://ejemplo.cl/apuntes'],
            ['titulo' => 'Manual de laboratorio', 'formato' => 'ambos', 'enlace' => 'https://ejemplo.cl/manual'],
        ]]]);
        self::assertResponseRedirects();
        self::em()->clear();

        $formato = fn (string $titulo) => self::em()->getRepository(Recurso::class)->findOneBy(['titulo' => $titulo])?->getFormato();
        self::assertSame(FormatoRecurso::Impreso, $formato('Introduction to algorithms'));
        self::assertSame(FormatoRecurso::Electronico, $formato('Apuntes en línea'));
        self::assertSame(FormatoRecurso::Ambos, $formato('Manual de laboratorio'));
        $bloque = self::em()->getRepository(Bloque::class)->findOneBy(['seccion' => $seccion->getId()]);
        self::assertSame(['impreso', 'electronico', 'ambos'], array_column($bloque->dato('items'), 'formato'));

        // La guía publicada muestra el formato de cada libro.
        $guia = self::em()->find(Guia::class, $guia->getId());
        $guia->publicar();
        self::em()->flush();
        $crawler = $this->cliente->request('GET', '/es/guias/quimica');
        self::assertSame(['Impreso', 'Electrónico', 'Impreso y electrónico'], $crawler->filter('.libro .formato')->each(fn ($f) => trim($f->text())));

        // Al editar el bloque, el formato es el del recurso (que pudo corregirse en el panel).
        $recurso = self::em()->getRepository(Recurso::class)->findOneBy(['titulo' => 'Apuntes en línea'])->setFormato(FormatoRecurso::Impreso);
        self::em()->flush();
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId().'/bloques/'.$bloque->getId().'/editar');
        self::assertSame('impreso', $crawler->filter('select[name="bloque[items][1][formato]"] option[selected]')->attr('value'));
    }

    public function testLaSugerenciaIndicaElFormato(): void
    {
        $sugerencia = (new Sugerencia())->setTipo(TipoRecurso::Libros)->setFormato(FormatoRecurso::Impreso)->setTitulo('Biología')->setUrl('https://ejemplo.cl/b');
        self::assertSame(FormatoRecurso::Impreso, $sugerencia->crearRecurso()->getFormato());
        self::assertNull($sugerencia->setTipo(TipoRecurso::BaseDatos)->getFormato());
    }
}
