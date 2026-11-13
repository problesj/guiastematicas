<?php

declare(strict_types=1);

namespace App\Lti;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Inicio de sesión OIDC y verificación del id_token de un lanzamiento LTI 1.3. El state y el nonce se guardan en la
 * caché del servidor (no en cookies: Moodle muestra la herramienta en un iframe, donde los navegadores bloquean las
 * cookies de terceros) y sirven una sola vez.
 */
final class Verificador
{
    private const VIGENCIA = 600;

    public function __construct(
        private readonly Plataforma $plataforma,
        private readonly HttpClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Dirección de la plataforma a la que se redirige el inicio OIDC, o null si la solicitud no es de la plataforma registrada. */
    public function iniciar(array $parametros, string $redirectUri): ?string
    {
        if (!$this->plataforma->configurada() || !$this->plataforma->esIssuer($parametros['iss'] ?? null)) {
            return null;
        }
        $clientId = (string) ($parametros['client_id'] ?? '');
        if ('' !== $clientId && $clientId !== $this->plataforma->clientId()) {
            return null;
        }
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $item = $this->cache->getItem('lti_estado_'.$state);
        $this->cache->save($item->set($nonce)->expiresAfter(self::VIGENCIA));

        $consulta = [
            'scope' => 'openid',
            'response_type' => 'id_token',
            'response_mode' => 'form_post',
            'prompt' => 'none',
            'client_id' => $this->plataforma->clientId(),
            'redirect_uri' => $redirectUri,
            'login_hint' => (string) ($parametros['login_hint'] ?? ''),
            'state' => $state,
            'nonce' => $nonce,
        ];
        if (isset($parametros['lti_message_hint'])) {
            $consulta['lti_message_hint'] = (string) $parametros['lti_message_hint'];
        }
        $auth = $this->plataforma->authUrl();

        return $auth.(str_contains($auth, '?') ? '&' : '?').http_build_query($consulta, '', '&', \PHP_QUERY_RFC3986);
    }

    /** @throws ErrorLti */
    public function verificar(string $state, string $idToken): Lanzamiento
    {
        if (!$this->plataforma->configurada()) {
            throw new ErrorLti('lti.error.sin_configurar');
        }
        $item = '' !== $state && ctype_xdigit($state) ? $this->cache->getItem('lti_estado_'.$state) : null;
        if (null === $item || !$item->isHit()) {
            throw new ErrorLti('lti.error.estado');
        }
        $nonce = (string) $item->get();
        $this->cache->deleteItem('lti_estado_'.$state); // un solo uso

        JWT::$leeway = 60;
        try {
            $claims = $this->decodificar($idToken, false);
        } catch (\Throwable) {
            // Moodle pudo cambiar su llave: se vuelve a pedir el conjunto de llaves una vez antes de rechazar.
            try {
                $claims = $this->decodificar($idToken, true);
            } catch (\Throwable $e) {
                $this->logger->warning('LTI: id_token rechazado: {error}', ['error' => $e->getMessage()]);
                throw new ErrorLti('lti.error.firma');
            }
        }

        $aud = (array) ($claims['aud'] ?? []);
        $valido = $this->plataforma->esIssuer($claims['iss'] ?? null)
            && \in_array($this->plataforma->clientId(), $aud, true)
            && (1 === \count($aud) || ($claims['azp'] ?? null) === $this->plataforma->clientId())
            && hash_equals($nonce, (string) ($claims['nonce'] ?? ''))
            && '1.3.0' === ($claims['https://purl.imsglobal.org/spec/lti/claim/version'] ?? null);
        if (!$valido) {
            throw new ErrorLti('lti.error.datos');
        }
        $lanzamiento = new Lanzamiento($claims);
        $despliegues = $this->plataforma->despliegues();
        if ('' === $lanzamiento->despliegue() || ([] !== $despliegues && !\in_array($lanzamiento->despliegue(), $despliegues, true))) {
            throw new ErrorLti('lti.error.despliegue');
        }
        if (!\in_array($lanzamiento->tipo(), [Lanzamiento::RESOURCE_LINK, Lanzamiento::DEEP_LINKING], true)) {
            throw new ErrorLti('lti.error.tipo');
        }

        return $lanzamiento;
    }

    /** @return array<string, mixed> */
    private function decodificar(string $idToken, bool $refrescar): array
    {
        return (array) json_decode((string) json_encode(JWT::decode($idToken, $this->llavesPlataforma($refrescar))), true);
    }

    /** Llaves públicas de la plataforma (JWKS), en caché una hora. @return array<string, \Firebase\JWT\Key> */
    private function llavesPlataforma(bool $refrescar): array
    {
        $url = $this->plataforma->jwksUrl();
        $item = $this->cache->getItem('lti_jwks_'.hash('sha256', $url));
        if ($refrescar || !$item->isHit()) {
            $jwks = $this->http->request('GET', $url, ['timeout' => 10])->toArray();
            $this->cache->save($item->set($jwks)->expiresAfter(3600));
        }

        return JWK::parseKeySet((array) $item->get(), 'RS256');
    }
}
