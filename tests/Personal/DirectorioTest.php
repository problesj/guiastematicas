<?php

declare(strict_types=1);

namespace App\Tests\Personal;

use App\Entity\Guia;
use App\Enum\EstadoGuia;
use App\Enum\Rol;
use App\Enum\VisibilidadGuia;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DirectorioTest extends WebTestCase
{
    use CreaDatosTrait;

    public function testMuestraSoloAlPersonalVisibleYActivo(): void
    {
        $cliente = static::createClient();
        $referencia = self::crearDepartamento('Referencia');
        self::crearUsuario('ana@ucn.cl', nombre: 'Ana', apellidos: 'Visible')->setDepartamento($referencia);
        self::crearUsuario('oculto@ucn.cl', visible: false, nombre: 'Oscar', apellidos: 'Oculto');
        self::crearUsuario('inactivo@ucn.cl', activo: false, nombre: 'Ines', apellidos: 'Inactiva');
        self::em()->flush();

        $cliente->request('GET', '/es/personal');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.grupo-personal h2', 'Referencia');
        self::assertAnySelectorTextContains('.persona__nombre', 'Ana Visible');
        self::assertAnySelectorTextNotContains('.persona__nombre', 'Oscar');
        self::assertAnySelectorTextNotContains('.persona__nombre', 'Ines');
    }

    public function testFiltraPorMateria(): void
    {
        $cliente = static::createClient();
        $medicina = self::crearMateria('Medicina', 'medicina');
        self::crearUsuario('ana@ucn.cl', nombre: 'Ana', apellidos: 'Medica')->addMateria($medicina);
        self::crearUsuario('bruno@ucn.cl', nombre: 'Bruno', apellidos: 'Legal');
        self::em()->flush();

        $crawler = $cliente->request('GET', '/es/personal?materia=medicina');

        self::assertSelectorTextContains('[role="status"]', 'Especialistas en Medicina');
        self::assertCount(1, $crawler->filter('.persona'));
    }

    public function testPerfilPublico(): void
    {
        $cliente = static::createClient();
        $ana = self::crearUsuario('ana@ucn.cl', nombre: 'Ana', apellidos: 'Pérez');
        $ana->setCargo('Bibliotecaria')->setCargoEn('Librarian')->setBiografia('Hola');
        self::em()->flush();

        $cliente->request('GET', '/en/personal/'.$ana->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ana Pérez');
        self::assertSelectorTextContains('.perfil__cargo', 'Librarian');
    }

    public function testPerfilOcultoDevuelve404(): void
    {
        $cliente = static::createClient();
        $oculto = self::crearUsuario('oculto@ucn.cl', visible: false);

        $cliente->request('GET', '/es/personal/'.$oculto->getSlug());

        self::assertResponseStatusCodeSame(404);
    }

    public function testCorreoLlevaAlFormularioYMuestraSusGuias(): void
    {
        $cliente = static::createClient();
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor, nombre: 'Ana', apellidos: 'Pérez');
        $otra = self::crearUsuario('otra@ucn.cl', Rol::Editor, nombre: 'Otra', apellidos: 'Editora');
        foreach ([
            ['Química', $ana, null, EstadoGuia::Publicada, VisibilidadGuia::Publica],
            ['Física', $otra, $ana, EstadoGuia::Publicada, VisibilidadGuia::Publica],
            ['Borrador', $ana, null, EstadoGuia::Borrador, VisibilidadGuia::Publica],
            ['Interna', $ana, null, EstadoGuia::Publicada, VisibilidadGuia::Restringida],
        ] as [$titulo, $responsable, $coeditor, $estado, $visibilidad]) {
            $guia = (new Guia())->setTitulo($titulo)->setSlug(strtolower(strtr($titulo, ['í' => 'i'])))->setResponsable($responsable)->setVisibilidad($visibilidad);
            if (EstadoGuia::Publicada === $estado) {
                $guia->publicar();
            }
            if (null !== $coeditor) {
                $guia->addCoeditor($coeditor);
            }
            self::em()->persist($guia);
        }
        self::em()->flush();

        $crawler = $cliente->request('GET', '/es/personal');
        $tarjeta = $crawler->filter('.persona')->reduce(fn ($t) => str_contains($t->text(), 'Ana Pérez'));
        self::assertSame('/es/personal/'.$ana->getSlug().'#escribir', $tarjeta->filter('.persona__contacto a')->attr('href'));
        $guias = $tarjeta->filter('.persona__guias')->each(fn ($p) => preg_replace('/\s+/', ' ', trim($p->text())));
        self::assertSame(['Editor de la guía: Química', 'Colaborador en la guía: Física'], $guias);

        $cliente->request('GET', '/es/personal/'.$ana->getSlug());
        self::assertSelectorExists('.datos a[href="#escribir"]');
        self::assertSelectorTextContains('.perfil__guias', 'Química');
    }
}
