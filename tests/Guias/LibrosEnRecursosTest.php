<?php

declare(strict_types=1);

namespace App\Tests\Guias;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Pestana;
use App\Entity\Recurso;
use App\Entity\Seccion;
use App\Entity\Usuario;
use App\Enum\Rol;
use App\Enum\TipoBloque;
use App\Enum\TipoRecurso;
use App\Servicio\LibrosEnRecursos;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Los libros del bloque Libros se incorporan a la lista de recursos bibliográficos, con sus materias. */
final class LibrosEnRecursosTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    private static function crearGuia(Usuario $responsable, Materia ...$materias): Guia
    {
        $guia = (new Guia())->setTitulo('Química')->setSlug('quimica')->setResponsable($responsable);
        $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion(new Seccion('Libros')));
        foreach ($materias as $materia) {
            $guia->addMateria($materia);
        }
        self::em()->persist($guia);
        self::em()->flush();

        return $guia;
    }

    /** @param list<array<string, mixed>> $items */
    private function enviarLibros(string $url, array $items): void
    {
        $crawler = $this->cliente->request('GET', $url);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="bloque[_token]"]')->attr('value');
        $this->cliente->request('POST', $url, ['bloque' => ['titulo' => 'Bibliografía', 'items' => $items, 'isbns' => '', '_token' => $token]]);
    }

    private static function recursoPorTitulo(string $titulo): ?Recurso
    {
        return self::em()->getRepository(Recurso::class)->findOneBy(['titulo' => $titulo]);
    }

    /** @return list<string> */
    private static function slugs(Recurso $recurso): array
    {
        $slugs = array_map(fn (Materia $m) => $m->getSlug(), $recurso->getMaterias()->toArray());
        sort($slugs);

        return $slugs;
    }

    public function testLosLibrosIngresadosQuedanEnLaListaDeRecursosConSusMaterias(): void
    {
        $quimica = self::crearMateria('Química', 'quimica');
        $computacion = self::crearMateria('Computación', 'computacion');
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana, $quimica);
        $seccion = $guia->getPestana()->getSecciones()->first();
        $this->cliente->loginUser($ana);

        $this->enviarLibros('/es/panel/guias/'.$guia->getId().'/secciones/'.$seccion->getId().'/bloques/libros', [
            ['isbn' => '978-0-262-03384-8', 'materias' => [(string) $computacion->getId()]],
            ['titulo' => 'Apuntes de química', 'enlace' => 'https://ejemplo.cl/apuntes'],
            ['isbn' => '9780262033848'], // repetido en el bloque: un solo recurso
        ]);
        self::assertResponseRedirects();
        self::em()->clear();

        $cormen = self::recursoPorTitulo('Introduction to algorithms');
        self::assertNotNull($cormen);
        self::assertSame(TipoRecurso::Libros, $cormen->getTipo());
        self::assertSame('9780262033848', $cormen->getIsbn());
        self::assertSame(2009, $cormen->getAnio());
        self::assertSame('Cormen, Thomas H.', $cormen->getAutoresTexto());
        self::assertStringStartsWith('https://primo.ejemplo.cl/discovery/fulldisplay?docid=alma', $cormen->getUrl());
        self::assertFalse($cormen->isRequiereProxy()); // el catálogo no pasa por el proxy
        self::assertTrue($cormen->isVisible());
        self::assertSame(['computacion'], self::slugs($cormen));

        $apuntes = self::recursoPorTitulo('Apuntes de química');
        self::assertSame('https://ejemplo.cl/apuntes', $apuntes->getUrl());
        self::assertTrue($apuntes->isRequiereProxy());
        self::assertSame(['quimica'], self::slugs($apuntes)); // sin materias elegidas: las de la guía
        self::assertSame(2, self::em()->getRepository(Recurso::class)->count([]));

        $bloque = self::em()->getRepository(Bloque::class)->findOneBy(['seccion' => $seccion->getId()]);
        self::assertSame([$cormen->getId(), $apuntes->getId(), $cormen->getId()], array_column($bloque->dato('items'), 'recurso'));

        // Aparecen en la lista pública A-Z.
        $this->cliente->request('GET', '/es/recursos-bibliograficos?q=algorithms');
        self::assertSelectorTextContains('main', 'Introduction to algorithms');

        // Al editar, el formulario muestra las materias del recurso; cambiarlas cambia las del recurso.
        $url = '/es/panel/guias/'.$guia->getId().'/bloques/'.$bloque->getId().'/editar';
        $crawler = $this->cliente->request('GET', $url);
        self::assertSame((string) $computacion->getId(), $crawler->filter('input[name="bloque[items][0][materias][]"]:checked')->attr('value'));
        self::assertCount(3, $crawler->filter('.libro-form__recurso a[href$="/editar"]'));
        $this->enviarLibros($url, [
            ['isbn' => '9780262033848', 'recurso' => (string) $cormen->getId(), 'materias' => [(string) $quimica->getId()]],
            ['titulo' => 'Apuntes de química', 'enlace' => 'https://ejemplo.cl/apuntes', 'recurso' => (string) $apuntes->getId()],
        ]);
        self::assertResponseRedirects();
        self::em()->clear();
        self::assertSame(['quimica'], self::slugs(self::em()->find(Recurso::class, $cormen->getId())));
        self::assertSame([], self::slugs(self::em()->find(Recurso::class, $apuntes->getId()))); // desmarcadas todas
        self::assertSame(2, self::em()->getRepository(Recurso::class)->count([]));
    }

    public function testUnLibroQueYaEstaEnLaListaNoSeDuplicaNiSeModifica(): void
    {
        $quimica = self::crearMateria('Química', 'quimica');
        $computacion = self::crearMateria('Computación', 'computacion');
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana, $quimica);
        $existente = (new Recurso())->setTitulo('Algoritmos (edición revisada)')->setTipo(TipoRecurso::Libros)
            ->setUrl('https://ebooks.ejemplo.cl/cormen')->setEisbn('0-262-03384-4')->addMateria($computacion);
        self::em()->persist($existente);
        self::em()->flush();
        $seccion = $guia->getPestana()->getSecciones()->first();
        $this->cliente->loginUser($ana);

        // Mismo libro con su ISBN de 13 dígitos.
        $this->enviarLibros('/es/panel/guias/'.$guia->getId().'/secciones/'.$seccion->getId().'/bloques/libros', [
            ['isbn' => '9780262033848', 'materias' => [(string) $quimica->getId()]],
        ]);
        self::assertResponseRedirects();
        self::em()->clear();

        self::assertSame(1, self::em()->getRepository(Recurso::class)->count([]));
        $recurso = self::em()->find(Recurso::class, $existente->getId());
        self::assertSame('Algoritmos (edición revisada)', $recurso->getTitulo());
        self::assertSame(['computacion', 'quimica'], self::slugs($recurso)); // la elegida se suma
    }

    public function testElComandoIncorporaLosLibrosGuardadosAntes(): void
    {
        $quimica = self::crearMateria('Química', 'quimica');
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $guia = self::crearGuia($ana, $quimica);
        $bloque = (new Bloque(TipoBloque::Libros))->setContenido(['presentacion' => 'lista', 'items' => [
            ['titulo' => 'Química general', 'autor' => 'Chang, Raymond', 'editorial' => 'McGraw-Hill', 'anio' => '2010', 'doi' => '10.1036/quimica', 'enlace' => null],
            ['titulo' => '', 'enlace' => null], // sin título: queda fuera
        ]]);
        $guia->getPestana()->getSecciones()->first()->agregarBloque($bloque);
        self::em()->flush();

        $comando = new CommandTester((new Application(self::$kernel))->find('app:incorporar-libros'));
        self::assertSame(0, $comando->execute([]));
        self::assertSame(0, $comando->execute([])); // otra vez: no duplica
        self::em()->clear();

        $recurso = self::recursoPorTitulo('Química general');
        self::assertSame('https://doi.org/10.1036/quimica', $recurso->getUrl());
        self::assertSame('10.1036/quimica', $recurso->getDoi());
        self::assertSame('McGraw-Hill', $recurso->getEditorial()?->getNombre());
        self::assertSame(['quimica'], self::slugs($recurso));
        self::assertSame(1, self::em()->getRepository(Recurso::class)->count([]));
        $items = self::em()->find(Bloque::class, $bloque->getId())->dato('items');
        self::assertSame([$recurso->getId(), null], array_column($items, 'recurso'));
        self::assertSame('lista', self::em()->find(Bloque::class, $bloque->getId())->dato('presentacion'));
    }

    public function testVariantesDeIsbn(): void
    {
        self::assertSame(['0262033844', '9780262033848'], LibrosEnRecursos::variantesIsbn('0-262-03384-4'));
        self::assertSame(['9780262033848', '0262033844'], LibrosEnRecursos::variantesIsbn('978-0-262-03384-8'));
        self::assertSame(['9791234567896'], LibrosEnRecursos::variantesIsbn('979-1-234-56789-6'));
    }
}
