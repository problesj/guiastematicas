<?php

declare(strict_types=1);

namespace App\Tests\Guias;

use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Seccion;
use App\Enum\Rol;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Botones para compartir una guía en redes sociales, WhatsApp y correo. */
final class CompartirTest extends WebTestCase
{
    use CreaDatosTrait;

    public function testLaGuiaOfreceCompartirSuDireccionPublica(): void
    {
        $cliente = static::createClient();
        $cliente->disableReboot();
        $guia = (new Guia())->setTitulo('Química & materiales')->setSlug('quimica')->setResponsable(self::crearUsuario('ana@ucn.cl', Rol::Editor));
        $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion(new Seccion()));
        $guia->agregarPestana((new Pestana('Bases de datos'))->agregarSeccion(new Seccion()));
        $guia->publicar();
        self::em()->persist($guia);
        self::em()->flush();

        $crawler = $cliente->request('GET', '/es/guias/quimica');
        self::assertResponseIsSuccessful();
        $url = urlencode('http://localhost/es/guias/quimica');
        $enlaces = $crawler->filter('.compartir a')->each(fn ($a) => $a->attr('href'));
        self::assertContains('https://www.facebook.com/sharer/sharer.php?u='.$url, $enlaces);
        self::assertContains('https://www.linkedin.com/sharing/share-offsite/?url='.$url, $enlaces);
        self::assertContains('https://x.com/intent/post?url='.$url.'&text='.rawurlencode('Química & materiales'), $enlaces);
        self::assertContains('https://wa.me/?text='.rawurlencode('Química & materiales http://localhost/es/guias/quimica'), $enlaces);
        self::assertContains('mailto:?subject='.rawurlencode('Química & materiales').'&body='.rawurlencode("Química & materiales\nhttp://localhost/es/guias/quimica"), $enlaces);
        self::assertSelectorExists('.compartir[data-compartir-url-value="http://localhost/es/guias/quimica"] button[data-action="compartir#copiar"]');

        // En una pestaña se comparte esa pestaña.
        $crawler = $cliente->request('GET', '/es/guias/quimica/bases-de-datos');
        self::assertSame('http://localhost/es/guias/quimica/bases-de-datos', $crawler->filter('.compartir')->attr('data-compartir-url-value'));
        self::assertSame('Química & materiales · Bases de datos', $crawler->filter('.compartir')->attr('data-compartir-titulo-value'));
    }
}
