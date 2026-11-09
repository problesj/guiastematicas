<?php

declare(strict_types=1);

namespace App\Tests\Recursos;

use App\Entity\Autor;
use App\Entity\Editorial;
use App\Entity\Pais;
use App\Entity\Recurso;
use App\Enum\Rol;
use App\Enum\Sexo;
use App\Enum\TipoRecurso;
use App\Repository\PaisRepository;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AutoresEditorialesTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    private static function pais(string $codigo, string $nombre): Pais
    {
        // En MySQL la migración ya cargó los países; en SQLite (esquema sin datos) se crea.
        $pais = static::getContainer()->get(PaisRepository::class)->porCodigo($codigo);
        if (null === $pais) {
            $pais = (new Pais())->setCodigo($codigo)->setNombre($nombre);
            self::em()->persist($pais);
            self::em()->flush();
        }

        return $pais;
    }

    private static function autor(string $apellidos, string $nombres = ''): Autor
    {
        $autor = (new Autor())->setApellidos($apellidos)->setNombres($nombres);
        self::em()->persist($autor);
        self::em()->flush();

        return $autor;
    }

    public function testElFormularioDeRecursosEligeAutoresExistentesYNuevosEnOrden(): void
    {
        $soto = self::autor('Soto', 'Luis');
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $crawler = $this->cliente->request('GET', '/es/panel/recursos/nuevo');
        self::assertCount(1, $crawler->filter('[data-controller="selector-lista"]'));

        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['recurso[autores]']->setValue(json_encode([
            ['apellidos' => 'Pérez', 'nombres' => 'Ana'],
            ['id' => $soto->getId(), 'nombre' => 'Soto, Luis'],
        ]));
        $this->cliente->submit($formulario, [
            'recurso[titulo]' => 'Introducción a la programación',
            'recurso[url]' => 'https://ebooks.org/1',
            'recurso[tipo]' => 'libros',
            'recurso[acceso]' => 'suscripcion',
            'recurso[editorial]' => '  Editorial   Universitaria ',
        ]);
        self::assertResponseRedirects('/es/panel/recursos');

        self::em()->clear();
        $libro = self::em()->getRepository(Recurso::class)->findOneBy(['titulo' => 'Introducción a la programación']);
        self::assertSame('Pérez, Ana; Soto, Luis', $libro->getAutoresTexto());
        self::assertSame('Editorial Universitaria', $libro->getEditorial()?->getNombre());
        self::assertSame($soto->getId(), $libro->getAutores()[1]->getId());

        // Al editar se ve la lista actual; se reordena y se elige la editorial existente (sin duplicarla).
        $crawler = $this->cliente->request('GET', '/es/panel/recursos/'.$libro->getId().'/editar');
        $actuales = json_decode($crawler->filter('#recurso_autores')->attr('value'), true);
        self::assertSame(['Pérez, Ana', 'Soto, Luis'], array_column($actuales, 'nombre'));
        self::assertSame('Editorial Universitaria', $crawler->filter('#recurso_editorial')->attr('value'));
        self::assertContains('Editorial Universitaria', $crawler->filter('#editoriales-existentes option')->each(fn ($o) => $o->attr('value')));

        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['recurso[autores]']->setValue(json_encode(array_reverse($actuales)));
        $this->cliente->submit($formulario, ['recurso[editorial]' => 'editorial universitaria']);
        self::assertResponseRedirects('/es/panel/recursos');

        self::em()->clear();
        $libro = self::em()->find(Recurso::class, $libro->getId());
        self::assertSame('Soto, Luis; Pérez, Ana', $libro->getAutoresTexto());
        self::assertCount(1, self::em()->getRepository(Editorial::class)->findAll());
        self::assertCount(2, self::em()->getRepository(Autor::class)->findAll());
    }

    public function testLosAutoresYEditorialSeVacianSiElTipoNoLosUsa(): void
    {
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $crawler = $this->cliente->request('GET', '/es/panel/recursos/nuevo');
        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['recurso[autores]']->setValue(json_encode([['apellidos' => 'Pérez', 'nombres' => 'Ana']]));
        $this->cliente->submit($formulario, [
            'recurso[titulo]' => 'Scopus',
            'recurso[url]' => 'https://www.scopus.com',
            'recurso[tipo]' => 'base_datos',
            'recurso[acceso]' => 'suscripcion',
            'recurso[editorial]' => 'Elsevier',
        ]);
        self::assertResponseRedirects('/es/panel/recursos');

        $scopus = self::em()->getRepository(Recurso::class)->findOneBy(['titulo' => 'Scopus']);
        self::assertSame([], $scopus->getAutores());
        self::assertNull($scopus->getEditorial());
        self::assertCount(0, self::em()->getRepository(Autor::class)->findAll());
        self::assertCount(0, self::em()->getRepository(Editorial::class)->findAll());
    }

    public function testBuscarAutoresDevuelveSugerencias(): void
    {
        self::autor('Pérez Rojas', 'Ana María')->setOrcid('0000-0002-1825-0097');
        self::autor('Soto', 'Luis');
        self::em()->flush();
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));

        $this->cliente->request('GET', '/es/panel/autores/buscar?q=rojas%20ana');
        self::assertResponseIsSuccessful();
        $respuesta = json_decode($this->cliente->getResponse()->getContent(), true);
        self::assertSame(['Pérez Rojas, Ana María'], array_column($respuesta, 'nombre'));
        self::assertSame('0000-0002-1825-0097', $respuesta[0]['orcid']);

        $this->cliente->request('GET', '/es/panel/autores/buscar?q=1825');
        self::assertCount(1, json_decode($this->cliente->getResponse()->getContent(), true));
    }

    public function testUnEditorAdministraAutoresConOrcid(): void
    {
        $chile = self::pais('CL', 'Chile');
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $this->cliente->request('GET', '/es/panel/autores/nuevo');
        $datos = [
            'autor[nombres]' => 'Ana',
            'autor[apellidos]' => 'Pérez',
            'autor[orcid]' => '0000-0002-1825-0098',
            'autor[sexo]' => 'femenino',
            'autor[pais]' => (string) $chile->getId(),
        ];
        $this->cliente->submitForm('Guardar', $datos);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.campo__errores li', 'El ORCID no es válido');

        $this->cliente->submitForm('Guardar', ['autor[orcid]' => 'https://orcid.org/0000-0002-1825-0097'] + $datos);
        self::assertResponseRedirects('/es/panel/autores');

        $autor = self::em()->getRepository(Autor::class)->findOneBy(['apellidos' => 'Pérez']);
        self::assertSame('0000-0002-1825-0097', $autor->getOrcid());
        self::assertSame(Sexo::Femenino, $autor->getSexo());
        self::assertSame('CL', $autor->getPais()?->getCodigo());

        $crawler = $this->cliente->request('GET', '/es/panel/autores?q='.rawurlencode('Pérez'));
        self::assertSelectorTextContains('.tabla tbody', 'Pérez');
        self::assertSame('https://orcid.org/0000-0002-1825-0097', $crawler->filter('.tabla tbody a[target="_blank"]')->attr('href'));

        // Otro autor con el mismo ORCID no se acepta.
        $this->cliente->request('GET', '/es/panel/autores/nuevo');
        $this->cliente->submitForm('Guardar', ['autor[apellidos]' => 'Otro', 'autor[orcid]' => '0000000218250097']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.campo__errores li', 'Ya hay otro autor con este ORCID');
    }

    public function testEliminarUnAutorLoQuitaDeSusRecursos(): void
    {
        $ana = self::autor('Pérez', 'Ana');
        $libro = (new Recurso())->setTitulo('Libro')->setUrl('https://ebooks.org/1')->setTipo(TipoRecurso::Libros)->setAutores([$ana]);
        self::em()->persist($libro);
        self::em()->flush();
        self::em()->clear();
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));

        $crawler = $this->cliente->request('GET', '/es/panel/autores');
        self::assertSame('1', $crawler->filter('.tabla tbody td')->eq(5)->text());
        $this->cliente->submit($crawler->filter('.tabla__acciones form')->form());
        self::assertResponseRedirects('/es/panel/autores');

        self::em()->clear();
        self::assertSame([], self::em()->find(Recurso::class, $libro->getId())->getAutores());
    }

    public function testUnEditorAdministraEditoriales(): void
    {
        $chile = self::pais('CL', 'Chile');
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $this->cliente->request('GET', '/es/panel/editoriales/nueva');
        $this->cliente->submitForm('Guardar', ['editorial[nombre]' => 'Ediciones UCN', 'editorial[pais]' => (string) $chile->getId()]);
        self::assertResponseRedirects('/es/panel/editoriales');

        $this->cliente->request('GET', '/es/panel/editoriales/nueva');
        $this->cliente->submitForm('Guardar', ['editorial[nombre]' => 'Ediciones UCN']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.campo__errores li', 'Ya existe una editorial');

        $editorial = self::em()->getRepository(Editorial::class)->findOneBy(['nombre' => 'Ediciones UCN']);
        $libro = (new Recurso())->setTitulo('Libro')->setUrl('https://ebooks.org/1')->setTipo(TipoRecurso::Libros)->setEditorial($editorial);
        self::em()->persist($libro);
        self::em()->flush();

        $crawler = $this->cliente->request('GET', '/es/panel/editoriales');
        self::assertSame('1', $crawler->filter('.tabla tbody td')->eq(2)->text());
        $this->cliente->submit($crawler->filter('.tabla__acciones form')->form());
        self::assertResponseRedirects('/es/panel/editoriales');

        self::em()->clear();
        self::assertNull(self::em()->find(Recurso::class, $libro->getId())->getEditorial());
    }

    public function testSoloLosAdministradoresEditanLosPaises(): void
    {
        $this->cliente->loginUser(self::crearUsuario('editor@ucn.cl', Rol::Editor));
        $this->cliente->request('GET', '/es/admin/paises');
        self::assertResponseStatusCodeSame(403);

        $this->cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));
        $crawler = $this->cliente->request('GET', '/es/admin/paises');
        self::assertResponseIsSuccessful();
        $this->cliente->submit($crawler->selectButton('Completar lista ISO')->form());
        self::assertResponseRedirects('/es/admin/paises');
        $crawler = $this->cliente->followRedirect();
        self::assertGreaterThan(240, $crawler->filter('.tabla tbody tr')->count());
        self::assertSame('Chile', self::em()->getRepository(Pais::class)->findOneBy(['codigo' => 'CL'])?->getNombre());

        $this->cliente->request('GET', '/es/admin/paises/nuevo');
        $this->cliente->submitForm('Guardar', ['pais[codigo]' => 'cl', 'pais[nombre]' => 'Chile otra vez']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.campo__errores li', 'Ya existe un país con este código');
    }

    public function testLaListaPublicaBuscaPorAutorYEditorial(): void
    {
        $libro = (new Recurso())->setTitulo('Geología del norte')->setUrl('https://ebooks.org/2')->setTipo(TipoRecurso::Libros)
            ->setAutores([self::autor('Pérez', 'Ana'), self::autor('Soto', 'Luis')])
            ->setEditorial((new Editorial())->setNombre('Ediciones UCN'));
        self::em()->persist($libro);
        self::recursoSimple('Scopus');

        foreach (['soto' => ['Geología del norte'], 'ediciones ucn' => ['Geología del norte'], 'scopus' => ['Scopus']] as $q => $esperado) {
            $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?q='.rawurlencode($q));
            self::assertSame($esperado, $crawler->filter('.recurso__titulo a')->each(fn ($n) => $n->text()), $q);
        }
        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos?q=norte');
        self::assertStringContainsString('Pérez, Ana; Soto, Luis', $crawler->filter('.recurso__datos')->text());
        self::assertStringContainsString('Ediciones UCN', $crawler->filter('.recurso__datos')->text());
    }

    private static function recursoSimple(string $titulo): void
    {
        self::em()->persist((new Recurso())->setTitulo($titulo)->setUrl('https://'.strtolower($titulo).'.com'));
        self::em()->flush();
    }
}
