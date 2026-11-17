<?php

declare(strict_types=1);

namespace App\Tests\Personal;

use App\Captcha\Captcha;
use App\Entity\Consulta;
use App\Entity\Usuario;
use App\Enum\Rol;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/** Formulario «Escribir a…» de la ficha del bibliotecario: registro, correos y respuestas desde el panel y el enlace privado. */
final class ConsultasTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient(server: ['REMOTE_ADDR' => '10.8.'.random_int(0, 255).'.'.random_int(1, 254)]);
    }

    private function bibliotecaria(): Usuario
    {
        return self::crearUsuario('marta@ucn.cl', Rol::Colaborador, nombre: 'Marta', apellidos: 'Díaz');
    }

    private function escribir(Usuario $persona, array $datos, bool $resolverCaptcha = true): void
    {
        $crawler = $this->cliente->request('GET', '/es/personal/'.$persona->getSlug());
        $form = $crawler->filter('form.consulta-form');
        $valores = $form->form()->getPhpValues();
        $valores['consulta'] = $datos + $valores['consulta'];
        if ($resolverCaptcha) {
            $valores['consulta']['captcha'] = Captcha::resolver(json_decode((string) $form->attr('data-captcha-desafio-value'), true));
        }
        $this->cliente->request('POST', '/es/personal/'.$persona->getSlug(), $valores);
    }

    private static function datos(): array
    {
        return ['nombre' => 'Camila Rojas', 'correo' => 'Camila@alumnos.ucn.cl', 'asunto' => 'Acceso a Scopus', 'mensaje' => "Hola, no puedo entrar a Scopus desde mi casa.\n¿Me ayudas?"];
    }

    /** @return list<Email> */
    private function correos(): array
    {
        return array_values(array_filter(self::getMailerMessages(), fn ($m) => $m instanceof Email));
    }

    private function consulta(): Consulta
    {
        self::em()->clear();
        $consulta = self::em()->getRepository(Consulta::class)->findOneBy([]);
        self::assertNotNull($consulta);

        return $consulta;
    }

    public function testLaPersonaEscribeYLleganLosDosCorreos(): void
    {
        $marta = $this->bibliotecaria();
        $this->escribir($marta, self::datos());
        self::assertResponseRedirects('/es/personal/'.$marta->getSlug().'#escribir');

        $correos = $this->correos();
        self::assertCount(2, $correos);
        [$alBibliotecario, $copia] = $correos;
        self::assertSame('marta@ucn.cl', $alBibliotecario->getTo()[0]->getAddress());
        self::assertSame('guias@ucn.cl', $alBibliotecario->getFrom()[0]->getAddress());
        self::assertStringContainsString('Nueva consulta: Acceso a Scopus', (string) $alBibliotecario->getSubject());
        self::assertStringContainsString('no puedo entrar a Scopus', (string) $alBibliotecario->getTextBody());
        $consulta = $this->consulta();
        self::assertStringContainsString('/es/panel/consultas/'.$consulta->getId(), (string) $alBibliotecario->getHtmlBody());
        self::assertSame('camila@alumnos.ucn.cl', $copia->getTo()[0]->getAddress());
        self::assertStringContainsString('/es/consulta/'.$consulta->getToken(), (string) $copia->getTextBody());

        self::assertTrue($consulta->isPendiente());
        self::assertCount(1, $consulta->getMensajes());
        self::assertTrue($consulta->getMensajes()->first()->isCorreoEnviado());

        $this->cliente->followRedirect();
        self::assertSelectorTextContains('.aviso--exito', 'Te enviamos una copia a camila@alumnos.ucn.cl');
    }

    public function testSinCaptchaNoSeEnvia(): void
    {
        $marta = $this->bibliotecaria();
        $this->escribir($marta, self::datos(), resolverCaptcha: false);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.aviso--error', 'No se pudo verificar');
        self::assertSame(0, self::em()->getRepository(Consulta::class)->count([]));
        self::assertCount(0, $this->correos());
    }

    public function testElBibliotecarioRespondeDesdeElPanelYLaPersonaDesdeSuEnlace(): void
    {
        $marta = $this->bibliotecaria();
        $this->escribir($marta, self::datos());
        $consulta = $this->consulta();

        // Otro colaborador no la ve.
        $this->cliente->loginUser(self::crearUsuario('otro@ucn.cl', Rol::Colaborador));
        $this->cliente->request('GET', '/es/panel/consultas/'.$consulta->getId());
        self::assertResponseStatusCodeSame(403);

        $this->cliente->loginUser(self::em()->find(Usuario::class, $marta->getId()));
        $this->cliente->request('GET', '/es/panel/consultas');
        self::assertSelectorTextContains('.panel__menu .contador', '1');
        self::assertSelectorTextContains('.tabla', 'Acceso a Scopus');
        $crawler = $this->cliente->request('GET', '/es/panel/consultas/'.$consulta->getId());
        self::assertSelectorTextContains('.conversacion', 'no puedo entrar a Scopus');
        $this->cliente->submit($crawler->selectButton('Enviar')->form(['texto' => 'Entra con tu cuenta UCN en AccesUM.']));
        self::assertResponseRedirects('/es/panel/consultas/'.$consulta->getId());
        $correos = $this->correos();
        self::assertCount(1, $correos);
        self::assertSame('camila@alumnos.ucn.cl', $correos[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('Entra con tu cuenta UCN en AccesUM.', (string) $correos[0]->getTextBody());
        self::assertFalse($this->consulta()->isPendiente());

        // La persona sigue la conversación con su enlace y vuelve a escribir.
        $this->cliente->request('GET', '/es/salir');
        $crawler = $this->cliente->request('GET', '/es/consulta/'.$consulta->getToken());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.conversacion', 'Entra con tu cuenta UCN en AccesUM.');
        $this->cliente->submit($crawler->selectButton('Enviar')->form(['texto' => '¡Funcionó, gracias!']));
        self::assertResponseRedirects('/es/consulta/'.$consulta->getToken());
        self::assertSame('marta@ucn.cl', $this->correos()[0]->getTo()[0]->getAddress());
        $consulta = $this->consulta();
        self::assertTrue($consulta->isPendiente());
        self::assertCount(3, $consulta->getMensajes());

        // Cerrada: la persona ya no puede escribir.
        $consulta->setCerrada(true);
        self::em()->flush();
        $this->cliente->request('GET', '/es/consulta/'.$consulta->getToken());
        self::assertAnySelectorTextContains('.aviso', 'Esta consulta fue cerrada');
        self::assertSelectorNotExists('textarea[name="texto"]');

        $this->cliente->request('GET', '/es/consulta/'.str_repeat('a', 64));
        self::assertResponseStatusCodeSame(404);
    }

    public function testSinServidorDeCorreoLaRespuestaQuedaRegistrada(): void
    {
        $marta = $this->bibliotecaria();
        $consulta = new Consulta($marta);
        $consulta->setNombre('Luis')->setCorreo('luis@ucn.cl')->setAsunto('Préstamo')->escribePersona('¿Puedo renovar?');
        self::em()->persist($consulta);
        self::em()->flush();

        $guardado = [$_SERVER['CORREO_DSN'] ?? null, $_ENV['CORREO_DSN'] ?? null];
        unset($_SERVER['CORREO_DSN'], $_ENV['CORREO_DSN']);
        try {
            $this->cliente->loginUser($marta);
            $crawler = $this->cliente->request('GET', '/es/panel/consultas/'.$consulta->getId());
            $this->cliente->submit($crawler->selectButton('Enviar')->form(['texto' => 'Sí, desde tu cuenta.']));
            $this->cliente->followRedirect();
            self::assertSelectorTextContains('.aviso--error', 'no se pudo enviar el correo');
            self::assertSelectorTextContains('.conversacion', 'Correo no enviado');
        } finally {
            $_SERVER['CORREO_DSN'] = $guardado[0];
            $_ENV['CORREO_DSN'] = $guardado[1];
        }
        self::assertCount(2, $this->consulta()->getMensajes());
    }

    public function testCorreoDePruebaDesdeIntegraciones(): void
    {
        $this->cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));
        $crawler = $this->cliente->request('GET', '/es/admin/configuracion');
        self::assertSelectorExists('select[name="form[CORREO_SMTP_CIFRADO]"]');
        $this->cliente->submit($crawler->selectButton('Enviar correo de prueba')->form());
        self::assertResponseRedirects('/es/admin/configuracion');
        self::assertCount(1, $this->correos());
        self::assertSame('admin@ucn.cl', $this->correos()[0]->getTo()[0]->getAddress());
    }
}
