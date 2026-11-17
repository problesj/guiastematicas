<?php

declare(strict_types=1);

namespace App\Tests\Guias;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Recurso;
use App\Entity\Seccion;
use App\Enum\Rol;
use App\Enum\TipoAcceso;
use App\Enum\TipoBloque;
use App\Enum\TipoRecurso;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Filtros por tipo en bloques con muchos recursos y portada con icono para libros y revistas sin imagen. */
final class FiltroRecursosTest extends WebTestCase
{
    use CreaDatosTrait;

    private function guia(int $bases, int $libros): void
    {
        $ids = [];
        foreach ([[TipoRecurso::BaseDatos, $bases], [TipoRecurso::Libros, $libros]] as [$tipo, $cuantos]) {
            for ($i = 1; $i <= $cuantos; ++$i) {
                $recurso = (new Recurso())->setTitulo($tipo->value.' '.$i)->setUrl('https://ejemplo.cl/'.$tipo->value.$i)->setTipo($tipo)->setAcceso(TipoAcceso::Suscripcion);
                self::em()->persist($recurso);
                self::em()->flush();
                $ids[] = $recurso->getId();
            }
        }
        $guia = (new Guia())->setTitulo('Química')->setSlug('quimica')->setResponsable(self::crearUsuario('ana@ucn.cl', Rol::Editor));
        $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion((new Seccion('Recursos'))
            ->agregarBloque((new Bloque(TipoBloque::Recursos))->setContenido(['ids' => $ids]))
            ->agregarBloque((new Bloque(TipoBloque::Libros))->setContenido(['items' => [['titulo' => 'Sin portada', 'autor' => null, 'isbn' => null, 'doi' => null, 'editorial' => null, 'anio' => null, 'enlace' => null]]]))
            ->agregarBloque((new Bloque(TipoBloque::Revistas))->setContenido(['items' => [['titulo' => 'Revista X']]]))));
        $guia->publicar();
        self::em()->persist($guia);
        self::em()->flush();
    }

    public function testConMasDe20RecursosDeVariosTiposSeOfrecenFiltrosPorTipo(): void
    {
        $cliente = static::createClient();
        $this->guia(15, 6);
        $crawler = $cliente->request('GET', '/es/guias/quimica');
        self::assertResponseIsSuccessful();
        self::assertSame(['Todos 21', 'Base de datos 15', 'Libros 6'], $crawler->filter('.filtro-tipos button')->each(fn ($b) => preg_replace('/\s+/', ' ', trim($b->text()))));
        self::assertCount(15, $crawler->filter('.bloque__recursos > li[data-tipo="base_datos"]'));

        // Portadas genéricas con icono del tipo.
        self::assertCount(1, $crawler->filter('.libro:not(.libro--revista) .libro__sin-portada .recurso__icono--libros'));
        self::assertCount(1, $crawler->filter('.libro--revista .libro__sin-portada .recurso__icono--revistas'));
    }

    public function testCon20RecursosOMenosNoHayFiltros(): void
    {
        $cliente = static::createClient();
        $this->guia(14, 6);
        $crawler = $cliente->request('GET', '/es/guias/quimica');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.filtro-tipos'));
        self::assertCount(20, $crawler->filter('.bloque__recursos > li'));
    }
}
