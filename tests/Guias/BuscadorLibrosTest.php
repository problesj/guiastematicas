<?php

declare(strict_types=1);

namespace App\Tests\Guias;

use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Seccion;
use App\Enum\Rol;
use App\Tests\CatalogoFalso;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Buscador del bloque Libros: catálogo (Primo VE) por título, autor y editorial, y Biblioges por carrera o asignatura. */
final class BuscadorLibrosTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->loginUser(self::crearUsuario('ana@ucn.cl', Rol::Editor));
    }

    /** @return array<string, mixed> */
    private function json(string $url, int $estado = 200): array
    {
        $this->cliente->request('GET', $url);
        self::assertResponseStatusCodeSame($estado);

        return json_decode((string) $this->cliente->getResponse()->getContent(), true);
    }

    public function testElFormularioDeLibrosOfreceElBuscador(): void
    {
        $guia = (new Guia())->setTitulo('Química')->setSlug('quimica')->setResponsable(self::em()->getRepository(\App\Entity\Usuario::class)->findOneBy([]));
        $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion(new Seccion('Libros')));
        self::em()->persist($guia);
        self::em()->flush();
        $seccion = $guia->getPestana()->getSecciones()->first();

        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId().'/secciones/'.$seccion->getId().'/bloques/libros');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="coleccion"] [data-controller="buscador-libros"]'));
        self::assertCount(2, $crawler->filter('input[name="buscador-libros-fuente"]'));
        self::assertSelectorTextContains('#titulo-buscador-libros', 'Buscar libros para agregar');

        // El buscador no se ofrece en otros bloques.
        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId().'/secciones/'.$seccion->getId().'/bloques/enlaces');
        self::assertCount(0, $crawler->filter('[data-controller="buscador-libros"]'));
    }

    public function testBuscaEnElCatalogoPorTituloExactoAutorYEditorial(): void
    {
        $datos = $this->json('/es/panel/guias/buscar-libros/catalogo?titulo=Introduction+to+algorithms&autor=Cormen');
        self::assertSame(2, $datos['total']);
        [$local, $central] = $datos['libros'];
        self::assertSame('Introduction to algorithms', $local['titulo']);
        self::assertSame('Cormen, Thomas H.', $local['autor']);
        self::assertSame('MIT Press', $local['editorial']);
        self::assertSame('9780262033848', $local['isbn']); // prefiere el ISBN de 13 dígitos
        self::assertSame(CatalogoFalso::MMS_CORMEN, $local['mms']);
        self::assertTrue($local['local']);
        self::assertNull($local['enlace']); // con ISBN el registro se recalcula al guardar
        self::assertNull($central['isbn']);
        self::assertFalse($central['local']);
        self::assertStringStartsWith('https://primo.ejemplo.cl/discovery/fulldisplay?docid=cdi_cormen_1990', $central['enlace']);

        // La editorial se busca en todos los campos y se filtra por la del registro.
        $datos = $this->json('/es/panel/guias/buscar-libros/catalogo?editorial=MIT+Press');
        self::assertSame(['MIT Press'], array_column($datos['libros'], 'editorial'));

        self::assertSame('parametros', $this->json('/es/panel/guias/buscar-libros/catalogo', 400)['error']);
    }

    public function testBuscaEnBibliogesPorCarreraOAsignaturaYTipo(): void
    {
        $carreras = $this->json('/es/panel/guias/buscar-libros/carreras')['carreras'];
        self::assertSame(['8213', '8606'], array_column($carreras, 'codigo')); // por nombre
        self::assertSame('Antofagasta', $carreras[0]['sede']);

        // Declarada de una carrera: agrupada por asignatura; las asignaturas sin bibliografía no aparecen.
        $datos = $this->json('/es/panel/guias/buscar-libros/biblioges?modo=carrera&codigo=8606&tipo=todas&fuente=declaradas');
        self::assertSame('8606 QUÍMICA', $datos['titulo']);
        self::assertSame(['QUIM-101 Química general'], array_column($datos['grupos'], 'nombre'));
        [$basica, $complementaria] = $datos['grupos'][0]['libros'];
        self::assertSame('Química', $basica['titulo']);
        self::assertSame('Chang, Raymond; Goldsby, Kenneth', $basica['autor']);
        self::assertSame('9786071509284', $basica['isbn']);
        self::assertSame('2013', $basica['anio']);
        self::assertSame('basica', $basica['tipo_bibliografia']);
        self::assertSame('complementaria', $complementaria['tipo_bibliografia']);

        // Solo la básica.
        $datos = $this->json('/es/panel/guias/buscar-libros/biblioges?modo=asignatura&codigo=QUIM-101&tipo=basica&fuente=declaradas');
        self::assertSame(['Química'], array_column($datos['grupos'][0]['libros'], 'titulo'));

        // Disponible: las ediciones de la biblioteca, con MMS o enlace de acceso.
        $datos = $this->json('/es/panel/guias/buscar-libros/biblioges?modo=carrera&codigo=8606&tipo=todas&fuente=disponibles');
        $libros = $datos['grupos'][0]['libros'];
        self::assertSame(['Química (11a ed.)', 'Química (e-book)'], array_column($libros, 'titulo'));
        self::assertSame('991000000005678', $libros[0]['mms']);
        self::assertSame([null, null], array_column($libros, 'isbn')); // dos ediciones: el ISBN declarado no es de ninguna en particular
        self::assertSame('https://ebooks.ejemplo.cl/quimica', $libros[1]['enlace']);
        self::assertSame('Chang, Raymond; Goldsby, Kenneth', $libros[1]['autor']); // sin autores: los de la declarada

        self::assertSame('no_encontrado', $this->json('/es/panel/guias/buscar-libros/biblioges?modo=asignatura&codigo=NO-EXISTE', 404)['error']);
        self::assertSame('no_autorizado', $this->json('/es/panel/guias/buscar-libros/biblioges?modo=asignatura&codigo=CLAVE-MALA', 502)['error']);
        self::assertSame('parametros', $this->json('/es/panel/guias/buscar-libros/biblioges?modo=asignatura&codigo=../x', 400)['error']);
    }
}
