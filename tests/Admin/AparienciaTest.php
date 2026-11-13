<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Configuracion\Apariencia;
use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Seccion;
use App\Enum\Rol;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AparienciaTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));
    }

    /** @param array<string, mixed> $campos */
    private function guardar(array $campos): void
    {
        $formulario = $this->cliente->request('GET', '/es/admin/apariencia')->selectButton('Guardar')->form();
        foreach ($campos as $campo => $valor) {
            if ($valor instanceof UploadedFile) {
                $formulario['form['.$campo.']']->upload($valor->getPathname());
            } elseif (true === $valor) {
                $formulario['form['.$campo.']']->tick();
            } else {
                $formulario['form['.$campo.']'] = $valor;
            }
        }
        $this->cliente->submit($formulario);
    }

    public function testNombreLogoYColoresDelSitio(): void
    {
        $this->guardar(['SITIO_TITULO' => 'Guías de investigación', 'SITIO_INSTITUCION' => 'Biblioteca Central', 'COLOR_PRINCIPAL' => '#1A5276']);
        self::assertResponseRedirects('/es/admin/apariencia');

        $crawler = $this->cliente->request('GET', '/es');
        self::assertSelectorTextSame('.marca__titulo', 'Guías de investigación');
        self::assertSelectorTextSame('.marca__institucion', 'Biblioteca Central');
        self::assertStringContainsString('Guías de investigación · Biblioteca Central', $crawler->filter('title')->text());
        self::assertStringContainsString('--color-principal:#1a5276;', $crawler->filter('head style')->text());
        // En inglés, sin título propio en inglés, se usa el de español.
        $this->cliente->request('GET', '/en');
        self::assertSelectorTextSame('.marca__titulo', 'Guías de investigación');

        // Logo: reemplaza al nombre de la institución (que queda como texto alternativo).
        $png = tempnam(sys_get_temp_dir(), 'logo').'.png';
        imagepng(imagecreatetruecolor(300, 80), $png);
        $this->guardar(['logo' => new UploadedFile($png, 'logo.png', 'image/png', null, true)]);
        self::assertResponseRedirects();
        $crawler = $this->cliente->request('GET', '/es');
        $logo = $crawler->filter('img.marca__logo');
        self::assertSame('Biblioteca Central', $logo->attr('alt'));
        $archivo = static::getContainer()->getParameter('kernel.project_dir').'/public/'.ltrim((string) parse_url((string) $logo->attr('src'), \PHP_URL_PATH), '/');
        self::assertFileExists($archivo);
        $this->guardar(['quitar_logo' => true]);
        self::assertFileDoesNotExist($archivo);
        self::assertSelectorNotExists('img.marca__logo');

        // Un color sin contraste suficiente con el texto no se guarda; «Volver al tema base» quita los colores.
        $this->guardar(['COLOR_PRINCIPAL' => '#f0c040']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.formulario--configuracion', 'debe ser al menos 4,5:1');
        $this->guardar(['restablecer' => true]);
        $this->cliente->request('GET', '/es');
        self::assertSelectorNotExists('head style');
    }

    public function testImagenDeFondoDeLaPortada(): void
    {
        $jpg = tempnam(sys_get_temp_dir(), 'portada').'.jpg';
        imagejpeg(imagecreatetruecolor(1200, 500), $jpg);
        $this->guardar(['imagen_portada' => new UploadedFile($jpg, 'portada.jpg', 'image/jpeg', null, true)]);
        self::assertResponseRedirects('/es/admin/apariencia');

        $crawler = $this->cliente->request('GET', '/es');
        $portada = $crawler->filter('.portada--imagen');
        self::assertCount(1, $portada);
        self::assertMatchesRegularExpression("#--imagen-portada: url\\('/uploads/sitio/portada-[a-f0-9]{16}\\.jpg'\\)#", (string) $portada->attr('style'));
        $archivo = static::getContainer()->getParameter('kernel.project_dir').'/public/'.Apariencia::RUTA_PUBLICA.'/'.basename((string) preg_replace("#.*/(portada-[^']+)'.*#", '$1', (string) $portada->attr('style')));
        self::assertFileExists($archivo);

        // Una imagen demasiado pequeña no se acepta; «Quitar la imagen» borra el archivo y vuelve al degradado.
        $chica = tempnam(sys_get_temp_dir(), 'portada').'.jpg';
        imagejpeg(imagecreatetruecolor(300, 200), $chica);
        $this->guardar(['imagen_portada' => new UploadedFile($chica, 'chica.jpg', 'image/jpeg', null, true)]);
        self::assertResponseStatusCodeSame(422);
        $this->guardar(['quitar_imagen_portada' => true]);
        self::assertFileDoesNotExist($archivo);
        $this->cliente->request('GET', '/es');
        self::assertSelectorNotExists('.portada--imagen');
    }

    public function testColoresDeTexto(): void
    {
        // Barra superior oscura con texto blanco, pie claro con texto oscuro, títulos y menú en azul.
        $this->guardar(['COLOR_BARRA' => '#1a3d6d', 'TEXTO_BARRA' => '#ffffff', 'COLOR_PIE' => '#eeeeee', 'TEXTO_PIE' => '#222222', 'TEXTO_TITULOS' => '#1a5276', 'TEXTO_CABECERA' => '#1a5276']);
        self::assertResponseRedirects('/es/admin/apariencia');
        $css = $this->cliente->request('GET', '/es')->filter('head style')->text();
        foreach (['--color-azul:#1a3d6d;', '--color-texto-barra:#ffffff;', '--color-pie:#eeeeee;', '--color-texto-pie:#222222;', '--color-texto-titulos:#1a5276;', '--color-texto-cabecera:#1a5276;'] as $variable) {
            self::assertStringContainsString($variable, $css);
        }
        // Lo que queda igual al tema base no se guarda.
        self::assertStringNotContainsString('--color-texto:', $css);

        // Cada texto se compara con su fondo: texto de la barra casi igual a la barra, o fondo del pie oscuro con su texto oscuro.
        $this->guardar(['TEXTO_BARRA' => '#2a4d7d']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.formulario--configuracion', 'El contraste con la barra superior');
        $this->guardar(['COLOR_PIE' => '#333333']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.formulario--configuracion', 'El contraste con el pie de página');
        // Texto oscuro sobre botones exige un color principal claro, pero el principal sigue siendo el color de los enlaces sobre blanco.
        $this->guardar(['TEXTO_BOTON' => '#1d1d1b']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.formulario--configuracion', 'El contraste con el color principal');

        $this->guardar(['restablecer' => true]);
        $this->cliente->request('GET', '/es');
        self::assertSelectorNotExists('head style');
    }

    public function testContrasteWcag(): void
    {
        self::assertEqualsWithDelta(21.0, Apariencia::contraste('#000000', '#ffffff'), 0.01);
        self::assertEqualsWithDelta(1.0, Apariencia::contraste('#7e9bc0', '#7e9bc0'), 0.01);
    }

    public function testPresentacionDeLasGuiasPorMateria(): void
    {
        $guia = (new Guia())->setTitulo('Química')->setSlug('quimica');
        $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion(new Seccion()));
        $guia->publicar();
        self::em()->persist($guia);
        self::em()->flush();

        // Por omisión, lista.
        $this->cliente->request('GET', '/es/guias');
        self::assertSelectorExists('ul.lista-guias--lista');

        $this->guardar(['VISTA_GUIAS' => 'cuadricula']);
        self::assertResponseRedirects('/es/admin/apariencia');
        $crawler = $this->cliente->request('GET', '/es/guias');
        self::assertSelectorExists('ul.lista-guias--cuadricula .lista-guias__icono--vacio');
        self::assertSame('Q', $crawler->filter('.lista-guias__icono--vacio')->text());

        $this->guardar(['VISTA_GUIAS' => 'carrusel']);
        $this->cliente->request('GET', '/es/guias');
        self::assertSelectorExists('.guias-carrusel[data-controller="carrusel"] ul.lista-guias--carrusel[data-carrusel-target="pista"]');
        self::assertSelectorTextContains('.lista-guias--carrusel h2 a', 'Química');

        $crawler = $this->cliente->request('GET', '/es/admin/apariencia');
        self::assertSame('carrusel', $crawler->filter('input[name="form[VISTA_GUIAS]"]:checked')->attr('value'));
    }
}
