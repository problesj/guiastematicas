<?php

declare(strict_types=1);

namespace App\Tests\Guias;

use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Seccion;
use App\Entity\Usuario;
use App\Enum\Rol;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Guías principales con subguías: datos, validación de ciclos y vista pública. */
final class SubguiasTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    private static function crearGuia(Usuario $responsable, string $slug, bool $publicada = true, ?Guia $principal = null): Guia
    {
        $guia = (new Guia())->setTitulo(ucfirst($slug))->setSlug($slug)->setResponsable($responsable)->setPrincipal($principal);
        $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion(new Seccion()));
        if ($publicada) {
            $guia->publicar();
        }
        self::em()->persist($guia);
        self::em()->flush();

        return $guia;
    }

    public function testSeEligeLaGuiaPrincipalEnLosDatos(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $ingenieria = self::crearGuia($ana, 'ingenieria');
        $civil = self::crearGuia($ana, 'civil');
        $this->cliente->loginUser($ana);

        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$civil->getId().'/datos');
        self::assertResponseIsSuccessful();
        // La propia guía no aparece entre las posibles principales.
        self::assertCount(0, $crawler->filter('select[name="guia[principal]"] option[value="'.$civil->getId().'"]'));
        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['guia[principal]']->select((string) $ingenieria->getId());
        $this->cliente->submit($formulario);
        self::assertResponseRedirects();

        self::em()->clear();
        $civil = self::em()->find(Guia::class, $civil->getId());
        self::assertSame($ingenieria->getId(), $civil->getPrincipal()?->getId());
        self::assertSame(['Civil'], $civil->getPrincipal()->getSubguias()->map(fn (Guia $g) => $g->getTitulo())->getValues());
    }

    public function testUnaGuiaNoPuedeColgarDeSuPropiaSubguia(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $ingenieria = self::crearGuia($ana, 'ingenieria');
        $civil = self::crearGuia($ana, 'civil', principal: $ingenieria);
        $estructuras = self::crearGuia($ana, 'estructuras', principal: $civil);
        $this->cliente->loginUser($ana);

        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$ingenieria->getId().'/datos');
        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['guia[principal]']->select((string) $estructuras->getId());
        $this->cliente->submit($formulario);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.formulario', 'no puede ser la misma guía ni una de sus subguías');

        self::em()->clear();
        self::assertNull(self::em()->find(Guia::class, $ingenieria->getId())->getPrincipal());
    }

    public function testLaPrincipalListaSusSubguiasYEstasMuestranLaRutaDeVuelta(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $ingenieria = self::crearGuia($ana, 'ingenieria');
        $civil = self::crearGuia($ana, 'civil', principal: $ingenieria);
        self::crearGuia($ana, 'borrador', publicada: false, principal: $ingenieria);
        self::crearGuia($ana, 'estructuras', principal: $civil);

        // La principal lista solo las subguías publicadas.
        $crawler = $this->cliente->request('GET', '/es/guias/ingenieria');
        self::assertResponseIsSuccessful();
        self::assertSame(['Civil'], $crawler->filter('.guia__subguias h4 a')->each(fn ($a) => $a->text()));

        // La subguía de segundo nivel muestra toda la ruta en las migas.
        $crawler = $this->cliente->request('GET', '/es/guias/estructuras');
        self::assertResponseIsSuccessful();
        self::assertSame(['Ingenieria', 'Civil'], $crawler->filter('.migas a[href^="/es/guias/"]')->each(fn ($a) => $a->text()));
        self::assertSelectorTextContains('.guia__meta', 'Parte de: Civil');

        // En la lista pública cada subguía indica de qué guía es parte.
        $crawler = $this->cliente->request('GET', '/es/guias');
        self::assertSame(['Parte de: Ingenieria', 'Parte de: Civil'], $crawler->filter('.lista-guias__parte')->each(fn ($p) => $p->text()));
    }
}
