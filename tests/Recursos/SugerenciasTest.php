<?php

declare(strict_types=1);

namespace App\Tests\Recursos;

use App\Captcha\Captcha;
use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Recurso;
use App\Entity\Sugerencia;
use App\Entity\Usuario;
use App\Enum\EstadoSugerencia;
use App\Enum\Rol;
use App\Enum\TipoRecurso;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Sugerir un recurso desde el sitio público (con captcha) y aprobarlo o rechazarlo desde el panel. */
final class SugerenciasTest extends WebTestCase
{
    use CreaDatosTrait;

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        // IP distinta en cada prueba: el límite de envíos se guarda en caché entre ejecuciones.
        $this->cliente = static::createClient(server: ['REMOTE_ADDR' => '10.9.'.random_int(0, 255).'.'.random_int(1, 254)]);
    }

    /** Llena y envía el formulario público resolviendo el captcha como lo haría el navegador. */
    private function sugerir(array $datos, array $materias, bool $resolverCaptcha = true): void
    {
        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos/sugerir');
        $form = $crawler->filter('form.sugerencia-form');
        $valores = $form->form()->getPhpValues();
        $valores['sugerencia'] = $datos + $valores['sugerencia'];
        $valores['sugerencia']['materias'] = array_map(fn (Materia $m) => (string) $m->getId(), $materias);
        if ($resolverCaptcha) {
            $valores['sugerencia']['captcha'] = Captcha::resolver(json_decode((string) $form->attr('data-captcha-desafio-value'), true));
        }
        $this->cliente->request('POST', '/es/recursos-bibliograficos/sugerir', $valores);
    }

    private static function datos(string $titulo = 'Statista'): array
    {
        return ['tipo' => 'base_datos', 'titulo' => $titulo, 'url' => 'https://www.statista.com', 'nombre' => 'Camila Rojas', 'correo' => 'camila@alumnos.ucn.cl', 'motivo' => 'Para el curso de marketing'];
    }

    private static function crearSugerencia(string $titulo, Materia ...$materias): Sugerencia
    {
        $sugerencia = (new Sugerencia())->setTitulo($titulo)->setTipo(TipoRecurso::Libros)->setNombre('Luis')->setCorreo('luis@ucn.cl')->setIdentificador('978-0-306-40615-7');
        foreach ($materias as $materia) {
            $sugerencia->addMateria($materia);
        }
        self::em()->persist($sugerencia);
        self::em()->flush();

        return $sugerencia;
    }

    private static function crearGuia(string $slug, Materia $materia, Usuario $responsable, ?Usuario $coeditor = null): Guia
    {
        $guia = (new Guia())->setTitulo(ucfirst($slug))->setSlug($slug)->setResponsable($responsable)->addMateria($materia);
        if (null !== $coeditor) {
            $guia->addCoeditor($coeditor);
        }
        self::em()->persist($guia);
        self::em()->flush();

        return $guia;
    }

    public function testElFormularioPublicoGuardaLaSugerenciaPendiente(): void
    {
        $materia = self::crearMateria('Ingeniería Comercial', 'ingenieria-comercial');
        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos/libros');
        self::assertSame(404, $this->cliente->getResponse()->getStatusCode(), 'el acceso Libros está deshabilitado');
        $crawler = $this->cliente->request('GET', '/es/recursos-bibliograficos');
        self::assertSelectorExists('a[href="/es/recursos-bibliograficos/sugerir"]');

        $this->sugerir(self::datos(), [$materia]);
        self::assertResponseRedirects('/es/recursos-bibliograficos/sugerir');
        $this->cliente->followRedirect();
        self::assertSelectorTextContains('.aviso--exito', 'Recibimos tu sugerencia');

        $guardada = self::em()->getRepository(Sugerencia::class)->findOneBy(['titulo' => 'Statista']);
        self::assertNotNull($guardada);
        self::assertSame(EstadoSugerencia::Pendiente, $guardada->getEstado());
        self::assertSame('camila@alumnos.ucn.cl', $guardada->getCorreo());
        self::assertSame(['Ingeniería Comercial'], $guardada->getMaterias()->map(fn (Materia $m) => $m->getNombre())->toArray());
    }

    public function testSinCaptchaOSinMateriaNoSeGuarda(): void
    {
        $materia = self::crearMateria('Derecho', 'derecho');
        $this->sugerir(self::datos(), [$materia], resolverCaptcha: false);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.aviso--error', 'No se pudo verificar');

        $this->sugerir(self::datos(), []);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.campo__errores', 'Elige al menos una materia');
        self::assertSame(0, self::em()->getRepository(Sugerencia::class)->count([]));
    }

    public function testElCaptchaSirveUnaSolaVezYNoSePuedeFalsificar(): void
    {
        $captcha = static::getContainer()->get(Captcha::class);
        $desafio = $captcha->desafio();
        $respuesta = Captcha::resolver($desafio);
        self::assertTrue($captcha->verificar($respuesta));
        self::assertFalse($captcha->verificar($respuesta), 'reutilizado');

        $otro = $captcha->desafio();
        $falso = ['challenge' => hash('sha256', $otro['salt'].'1')] + $otro; // desafío propio con la firma de otro
        self::assertFalse($captcha->verificar(Captcha::resolver($falso)));
        self::assertFalse($captcha->verificar('basura'));
        self::assertFalse($captcha->verificar(null));
    }

    public function testLimiteDeEnviosPorIp(): void
    {
        $this->cliente->disableReboot();
        $materia = self::crearMateria('Geología', 'geologia');
        for ($i = 1; $i <= 5; ++$i) {
            $this->sugerir(self::datos('Recurso '.$i), [$materia]);
            self::assertResponseRedirects();
        }
        $this->sugerir(self::datos('Recurso 6'), [$materia]);
        self::assertResponseStatusCodeSame(429);
        self::assertSame(5, self::em()->getRepository(Sugerencia::class)->count([]));
    }

    public function testCadaPersonaVeLasSugerenciasDeLasMateriasDeSusGuias(): void
    {
        [$quimica, $fisica, $arte] = [self::crearMateria('Química', 'quimica'), self::crearMateria('Física', 'fisica'), self::crearMateria('Arte', 'arte')];
        $editora = self::crearUsuario('editora@ucn.cl', Rol::Editor);
        $colaborador = self::crearUsuario('colaborador@ucn.cl', Rol::Colaborador);
        self::crearGuia('quimica', $quimica, $editora);
        self::crearGuia('fisica', $fisica, self::crearUsuario('otra@ucn.cl'), $colaborador);
        $deQuimica = self::crearSugerencia('Manual de química', $quimica);
        $deFisica = self::crearSugerencia('Física de Serway', $fisica);
        self::crearSugerencia('Historia del arte', $arte);

        $this->cliente->loginUser($editora);
        $this->cliente->request('GET', '/es/panel/sugerencias');
        self::assertSame(['Manual de química'], $this->titulos());
        self::assertSelectorTextContains('.panel__menu .contador', '1');
        $this->cliente->request('GET', '/es/panel/sugerencias/'.$deFisica->getId());
        self::assertResponseStatusCodeSame(403);

        $this->cliente->loginUser($colaborador);
        $this->cliente->request('GET', '/es/panel/sugerencias');
        self::assertSame(['Física de Serway'], $this->titulos());
        $this->cliente->request('GET', '/es/panel/sugerencias/'.$deQuimica->getId());
        self::assertResponseStatusCodeSame(403);

        // Sin guía de Arte: solo la ven los administradores.
        $this->cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));
        $this->cliente->request('GET', '/es/panel/sugerencias');
        self::assertSame(['Manual de química', 'Física de Serway', 'Historia del arte'], $this->titulos());
    }

    /** @return list<string> */
    private function titulos(): array
    {
        return $this->cliente->getCrawler()->filter('.tabla tbody td:first-child')->each(fn ($td) => trim(explode("\n", $td->text())[0]));
    }

    public function testElColaboradorApruebaYElRecursoEntraALaLista(): void
    {
        $fisica = self::crearMateria('Física', 'fisica');
        $colaborador = self::crearUsuario('colaborador@ucn.cl', Rol::Colaborador);
        self::crearGuia('fisica', $fisica, self::crearUsuario('otra@ucn.cl'), $colaborador);
        $sugerencia = self::crearSugerencia('Física de Serway', $fisica);
        self::em()->persist((new Recurso())->setTitulo('Física de Serway')->setUrl('https://antiguo.cl')->setTipo(TipoRecurso::Libros));
        self::em()->flush();

        $this->cliente->loginUser($colaborador);
        $crawler = $this->cliente->request('GET', '/es/panel/sugerencias/'.$sugerencia->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.aviso', 'Posible duplicado');
        $formulario = $crawler->selectButton('Aprobar y agregar a la lista')->form();
        self::assertSame('Física de Serway', $formulario['recurso[titulo]']->getValue());
        self::assertSame('978-0-306-40615-7', $formulario['recurso[isbn]']->getValue());
        $formulario['recurso[url]'] = 'https://catalogo.ucn.cl/serway';
        $this->cliente->submit($formulario);
        self::assertResponseRedirects('/es/panel/sugerencias');

        self::em()->clear();
        $sugerencia = self::em()->find(Sugerencia::class, $sugerencia->getId());
        self::assertSame(EstadoSugerencia::Aprobada, $sugerencia->getEstado());
        self::assertSame('colaborador@ucn.cl', $sugerencia->getDecididaPor()?->getEmail());
        $recurso = $sugerencia->getRecurso();
        self::assertNotNull($recurso);
        self::assertSame('https://catalogo.ucn.cl/serway', $recurso->getUrl());
        self::assertSame(['Física'], $recurso->getMaterias()->map(fn (Materia $m) => $m->getNombre())->toArray());

        // Ya revisada: se ve la decisión, sin formulario.
        $this->cliente->request('GET', '/es/panel/sugerencias/'.$sugerencia->getId());
        self::assertSelectorTextContains('main', 'Aprobada por Ana Pérez');
        self::assertSelectorNotExists('form[name="recurso"]');

        // El colaborador puede usar el buscador de autores del formulario, pero no administrarlos.
        $this->cliente->request('GET', '/es/panel/autores/buscar?q=se');
        self::assertResponseIsSuccessful();
        $this->cliente->request('GET', '/es/panel/autores');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRechazarConMotivo(): void
    {
        $quimica = self::crearMateria('Química', 'quimica');
        $editora = self::crearUsuario('editora@ucn.cl', Rol::Editor);
        self::crearGuia('quimica', $quimica, $editora);
        $sugerencia = self::crearSugerencia('Spam', $quimica);

        $this->cliente->loginUser($editora);
        $crawler = $this->cliente->request('GET', '/es/panel/sugerencias/'.$sugerencia->getId());
        $formulario = $crawler->selectButton('Rechazar sugerencia')->form(['motivo' => 'No es un recurso académico']);
        $this->cliente->submit($formulario);
        self::assertResponseRedirects('/es/panel/sugerencias');

        self::em()->clear();
        $sugerencia = self::em()->find(Sugerencia::class, $sugerencia->getId());
        self::assertSame(EstadoSugerencia::Rechazada, $sugerencia->getEstado());
        self::assertSame('No es un recurso académico', $sugerencia->getMotivoRechazo());
        self::assertSame(0, self::em()->getRepository(Recurso::class)->count([]));

        $this->cliente->request('GET', '/es/panel/sugerencias?estado=rechazada');
        self::assertSame(['Spam'], $this->titulos());
    }
}
