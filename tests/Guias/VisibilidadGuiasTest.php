<?php

declare(strict_types=1);

namespace App\Tests\Guias;

use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Seccion;
use App\Entity\Usuario;
use App\Enum\Rol;
use App\Enum\VisibilidadGuia;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Guías públicas o restringidas a usuarios asignados, y el rol Lector. */
final class VisibilidadGuiasTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    private static function crearGuia(Usuario $responsable, string $slug, VisibilidadGuia $visibilidad, array $lectores = []): Guia
    {
        $guia = (new Guia())->setTitulo(ucfirst($slug))->setSlug($slug)->setResponsable($responsable)->setVisibilidad($visibilidad);
        $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion(new Seccion()));
        foreach ($lectores as $lector) {
            $guia->addLector($lector);
        }
        $guia->publicar();
        self::em()->persist($guia);
        self::em()->flush();

        return $guia;
    }

    /** @return list<string> títulos de la lista pública */
    private function lista(): array
    {
        $crawler = $this->cliente->request('GET', '/es/guias');
        self::assertResponseIsSuccessful();

        return $crawler->filter('.lista-guias__guia h2 a')->each(fn ($a) => $a->text());
    }

    private function entrar(string $email): void
    {
        $this->cliente->request('GET', '/es/acceso');
        $this->cliente->submitForm('Entrar', ['identificador' => $email, 'contrasena' => self::CONTRASENA]);
    }

    public function testUnaGuiaRestringidaSoloLaVenSusLectoresYQuienesLaEditan(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $coeditor = self::crearUsuario('coeditor@ucn.cl', Rol::Colaborador);
        $elena = self::crearUsuario('elena@ucn.cl', Rol::Lector);
        $otro = self::crearUsuario('otro@ucn.cl', Rol::Lector);
        $admin = self::crearUsuario('admin@ucn.cl', Rol::Administrador);
        self::crearGuia($ana, 'quimica', VisibilidadGuia::Publica);
        $taller = self::crearGuia($ana, 'taller', VisibilidadGuia::Restringida, [$elena]);
        $taller->addCoeditor($coeditor);
        self::em()->flush();

        // Sin sesión: no aparece en la lista y al abrirla pide iniciar sesión.
        self::assertSame(['Quimica'], $this->lista());
        $this->cliente->request('GET', '/es/guias/taller');
        self::assertResponseRedirects('/es/acceso');

        // La lectora asignada entra y vuelve a la guía.
        $this->entrar('elena@ucn.cl');
        self::assertResponseRedirects('http://localhost/es/guias/taller');
        $crawler = $this->cliente->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1 .insignia--restringida', 'Acceso restringido');
        self::assertStringContainsString('no-store', (string) $this->cliente->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex', $this->cliente->getResponse()->headers->get('X-Robots-Tag'));
        self::assertCount(0, $crawler->filter('a[href="/es/panel"]')); // un lector no ve el enlace al panel
        self::assertSame(['Quimica', 'Taller'], $this->lista());

        // Otro lector no la ve; el coeditor y los administradores sí.
        $this->cliente->loginUser($otro);
        $this->cliente->request('GET', '/es/guias/taller');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['Quimica'], $this->lista());
        foreach ([$coeditor, $admin] as $persona) {
            $this->cliente->loginUser($persona);
            $this->cliente->request('GET', '/es/guias/taller');
            self::assertResponseIsSuccessful();
        }
    }

    public function testUnLectorNoEntraAlPanelNiApareceEnElDirectorio(): void
    {
        self::crearUsuario('elena@ucn.cl', Rol::Lector, nombre: 'Elena');
        self::crearUsuario('ana@ucn.cl', Rol::Editor);

        $this->entrar('elena@ucn.cl');
        self::assertResponseRedirects('/es/guias');
        $this->cliente->request('GET', '/es/panel');
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->cliente->request('GET', '/es/personal');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Elena', $crawler->filter('main')->text());
        $this->cliente->request('GET', '/es/personal/elena-ucn-cl');
        self::assertResponseStatusCodeSame(404);
    }

    public function testElResponsableEligeLaVisibilidadYLosLectores(): void
    {
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor);
        $elena = self::crearUsuario('elena@ucn.cl', Rol::Lector);
        $guia = self::crearGuia($ana, 'taller', VisibilidadGuia::Publica);
        $this->cliente->loginUser($ana);

        $crawler = $this->cliente->request('GET', '/es/panel/guias/'.$guia->getId().'/datos');
        self::assertResponseIsSuccessful();
        // Un lector se puede asignar a la guía, pero no como coeditor.
        self::assertCount(0, $crawler->filter('input[name="guia[coeditores][]"][value="'.$elena->getId().'"]'));
        $formulario = $crawler->selectButton('Guardar')->form();
        $formulario['guia[visibilidad]']->select('restringida');
        foreach ($formulario['guia[lectores]'] as $casilla) {
            if ((string) $elena->getId() === $casilla->availableOptionValues()[0]) {
                $casilla->tick();
            }
        }
        $this->cliente->submit($formulario);
        self::assertResponseRedirects();

        self::em()->clear();
        $guia = self::em()->find(Guia::class, $guia->getId());
        self::assertTrue($guia->isRestringida());
        self::assertSame([$elena->getId()], $guia->getLectores()->map(fn (Usuario $u) => $u->getId())->getValues());
    }
}
