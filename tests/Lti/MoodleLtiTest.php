<?php

declare(strict_types=1);

namespace App\Tests\Lti;

use App\Configuracion\Ajustes;
use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Seccion;
use App\Tests\CatalogoFalso;
use App\Tests\CreaDatosTrait;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Herramienta LTI 1.3 para Moodle: inicio OIDC, lanzamiento a una guía y vinculación profunda (elegir la guía). */
final class MoodleLtiTest extends WebTestCase
{
    use CreaDatosTrait;

    private const ISS = 'https://moodle.prueba';
    private const CLIENTE = 'cliente-guias';

    private KernelBrowser $cliente;
    private string $llavePlataforma;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->llavePlataforma = self::nuevaLlave();
        CatalogoFalso::$jwksLti = self::jwks($this->llavePlataforma, 'moodle-1');
        static::getContainer()->get('cache.app')->clear();

        $ajustes = static::getContainer()->get(Ajustes::class);
        foreach ([
            'LTI_ISSUER' => self::ISS,
            'LTI_CLIENT_ID' => self::CLIENTE,
            'LTI_DEPLOYMENT_ID' => '7',
            'LTI_AUTH_URL' => self::ISS.'/mod/lti/auth.php',
            'LTI_JWKS_URL' => self::ISS.'/mod/lti/certs.php',
        ] as $clave => $valor) {
            $ajustes->guardar($clave, $valor);
        }

        foreach (['quimica' => 'Química general', 'fisica' => 'Física'] as $slug => $titulo) {
            $guia = (new Guia())->setTitulo($titulo)->setSlug($slug)->setDescripcion('Guía de '.$titulo);
            $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion(new Seccion()));
            $guia->publicar();
            self::em()->persist($guia);
        }
        self::em()->flush();
    }

    protected function tearDown(): void
    {
        CatalogoFalso::$jwksLti = null;
        parent::tearDown();
    }

    private static function nuevaLlave(): string
    {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]), $pem);

        return $pem;
    }

    private static function jwks(string $privada, string $kid): array
    {
        $rsa = openssl_pkey_get_details(openssl_pkey_get_private($privada))['rsa'];
        $b64 = fn (string $b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');

        return ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]]];
    }

    /** @return array{state: string, nonce: string, redirect_uri: string} */
    private function iniciar(): array
    {
        $this->cliente->request('POST', '/lti/login', ['iss' => self::ISS, 'login_hint' => '25', 'target_link_uri' => 'http://localhost/lti/lanzar', 'lti_message_hint' => 'xyz', 'client_id' => self::CLIENTE, 'lti_deployment_id' => '7']);
        self::assertResponseRedirects();
        $destino = (string) $this->cliente->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::ISS.'/mod/lti/auth.php?', $destino);
        parse_str((string) parse_url($destino, \PHP_URL_QUERY), $q);
        self::assertSame(['openid', 'id_token', 'form_post', 'none', self::CLIENTE, '25', 'xyz'], [$q['scope'], $q['response_type'], $q['response_mode'], $q['prompt'], $q['client_id'], $q['login_hint'], $q['lti_message_hint']]);

        return ['state' => $q['state'], 'nonce' => $q['nonce'], 'redirect_uri' => $q['redirect_uri']];
    }

    private function token(string $nonce, array $extra = [], ?string $llave = null): string
    {
        $ahora = time();

        return JWT::encode($extra + [
            'iss' => self::ISS,
            'aud' => self::CLIENTE,
            'sub' => '25',
            'iat' => $ahora,
            'exp' => $ahora + 60,
            'nonce' => $nonce,
            'https://purl.imsglobal.org/spec/lti/claim/version' => '1.3.0',
            'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => '7',
            'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiResourceLinkRequest',
            'https://purl.imsglobal.org/spec/lti/claim/resource_link' => ['id' => '3'],
            'https://purl.imsglobal.org/spec/lti/claim/context' => ['id' => '2', 'title' => 'Química I'],
        ], $llave ?? $this->llavePlataforma, 'RS256', CatalogoFalso::$jwksLti['keys'][0]['kid']);
    }

    public function testElEnlaceDelCursoAbreLaGuiaElegida(): void
    {
        $inicio = $this->iniciar();
        self::assertSame('http://localhost/lti/lanzar', $inicio['redirect_uri']);

        $token = $this->token($inicio['nonce'], ['https://purl.imsglobal.org/spec/lti/claim/custom' => ['guia' => 'quimica']]);
        $this->cliente->request('POST', '/lti/lanzar', ['state' => $inicio['state'], 'id_token' => $token]);
        self::assertResponseRedirects('/es/guias/quimica', 303);

        // El state sirve una sola vez.
        $this->cliente->request('POST', '/lti/lanzar', ['state' => $inicio['state'], 'id_token' => $token]);
        self::assertResponseStatusCodeSame(401);
        self::assertSelectorTextContains('main', 'venció o ya se usó');
        self::assertSame('frame-ancestors https://moodle.prueba', $this->cliente->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testRechazaFirmasNoncesYDesplieguesAjenos(): void
    {
        $casos = [
            'firma' => fn (string $nonce) => $this->token($nonce, [], self::nuevaLlave()),
            'nonce' => fn (string $nonce) => $this->token('otro-nonce'),
            'despliegue' => fn (string $nonce) => $this->token($nonce, ['https://purl.imsglobal.org/spec/lti/claim/deployment_id' => '99']),
            'destinatario' => fn (string $nonce) => $this->token($nonce, ['aud' => 'otra-herramienta']),
        ];
        foreach ($casos as $caso => $token) {
            $inicio = $this->iniciar();
            $this->cliente->request('POST', '/lti/lanzar', ['state' => $inicio['state'], 'id_token' => $token($inicio['nonce'])]);
            self::assertResponseStatusCodeSame(401, $caso);
        }

        // Si Moodle cambia su llave, se vuelven a pedir sus llaves públicas y el lanzamiento funciona.
        $this->llavePlataforma = self::nuevaLlave();
        CatalogoFalso::$jwksLti = self::jwks($this->llavePlataforma, 'moodle-2');
        $inicio = $this->iniciar();
        $this->cliente->request('POST', '/lti/lanzar', ['state' => $inicio['state'], 'id_token' => $this->token($inicio['nonce'])]);
        self::assertResponseRedirects('/es/guias', 303);

        // Una plataforma que no es la registrada no puede iniciar.
        $this->cliente->request('GET', '/lti/login?iss=https://otro.moodle&login_hint=1');
        self::assertResponseStatusCodeSame(400);
    }

    public function testElDocenteEligeLaGuiaYMoodleRecibeElEnlaceFirmado(): void
    {
        $inicio = $this->iniciar();
        $token = $this->token($inicio['nonce'], [
            'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiDeepLinkingRequest',
            'https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings' => [
                'deep_link_return_url' => self::ISS.'/mod/lti/contentitem_return.php?course=2',
                'accept_types' => ['ltiResourceLink'],
                'accept_presentation_document_targets' => ['iframe', 'window'],
                'data' => 'dato-opaco',
            ],
        ]);
        $crawler = $this->cliente->request('POST', '/lti/lanzar', ['state' => $inicio['state'], 'id_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Química I');
        self::assertSame(['fisica', 'quimica'], $crawler->filter('input[name="guia"]')->each(fn ($i) => $i->attr('value')));

        $formulario = $crawler->selectButton('Agregar al curso')->form();
        $formulario['guia']->select('quimica');
        $crawler = $this->cliente->submit($formulario);
        self::assertResponseIsSuccessful();
        $envio = $crawler->filter('form#lti-respuesta');
        self::assertSame(self::ISS.'/mod/lti/contentitem_return.php?course=2', $envio->attr('action'));

        // Moodle verifica la respuesta con la llave pública de /lti/jwks.
        $this->cliente->request('GET', '/lti/jwks');
        $jwks = json_decode((string) $this->cliente->getResponse()->getContent(), true);
        $respuesta = (array) json_decode((string) json_encode(JWT::decode($envio->filter('input[name="JWT"]')->attr('value'), JWK::parseKeySet($jwks))), true);
        self::assertSame(self::CLIENTE, $respuesta['iss']);
        self::assertSame([self::ISS], $respuesta['aud']);
        self::assertSame('LtiDeepLinkingResponse', $respuesta['https://purl.imsglobal.org/spec/lti/claim/message_type']);
        self::assertSame('7', $respuesta['https://purl.imsglobal.org/spec/lti/claim/deployment_id']);
        self::assertSame('dato-opaco', $respuesta['https://purl.imsglobal.org/spec/lti-dl/claim/data']);
        self::assertSame([[
            'type' => 'ltiResourceLink',
            'title' => 'Química general',
            'text' => 'Guía de Química general',
            'url' => 'http://localhost/lti/lanzar',
            'custom' => ['guia' => 'quimica'],
        ]], $respuesta['https://purl.imsglobal.org/spec/lti-dl/claim/content_items']);

        // La elección se usa una sola vez.
        $this->cliente->submit($formulario);
        self::assertResponseStatusCodeSame(400);
    }
}
