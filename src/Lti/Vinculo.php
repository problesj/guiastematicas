<?php

declare(strict_types=1);

namespace App\Lti;

use Firebase\JWT\JWT;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Vinculación profunda (Deep Linking 2.0): guarda el pedido de Moodle mientras el docente elige la guía y arma la
 * respuesta firmada con el enlace elegido.
 */
final class Vinculo
{
    private const VIGENCIA = 1800;

    public function __construct(
        private readonly Plataforma $plataforma,
        private readonly LlavesHerramienta $llaves,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /** Guarda el pedido y devuelve su identificador (es la única credencial del formulario de elección). */
    public function guardar(Lanzamiento $lanzamiento): string
    {
        $id = bin2hex(random_bytes(24));
        $item = $this->cache->getItem('lti_vinculo_'.$id);
        $this->cache->save($item->set([
            'retorno' => (string) ($lanzamiento->ajustesVinculo()['deep_link_return_url'] ?? ''),
            'data' => $lanzamiento->ajustesVinculo()['data'] ?? null,
            'despliegue' => $lanzamiento->despliegue(),
            'curso' => $lanzamiento->curso(),
            'idioma' => $lanzamiento->idioma(),
        ])->expiresAfter(self::VIGENCIA));

        return $id;
    }

    /** @return array{retorno: string, data: ?string, despliegue: string, curso: ?string, idioma: string}|null */
    public function leer(string $id): ?array
    {
        if ('' === $id || !ctype_xdigit($id)) {
            return null;
        }
        $item = $this->cache->getItem('lti_vinculo_'.$id);

        return $item->isHit() ? $item->get() : null;
    }

    public function olvidar(string $id): void
    {
        if ('' !== $id && ctype_xdigit($id)) {
            $this->cache->deleteItem('lti_vinculo_'.$id);
        }
    }

    /**
     * JWT LtiDeepLinkingResponse firmado con la llave de la herramienta.
     *
     * @param array{retorno: string, data: ?string, despliegue: string} $pedido
     * @param list<array<string, mixed>>                                $elementos content_items
     */
    public function respuesta(array $pedido, array $elementos): string
    {
        $ahora = time();
        $datos = [
            'iss' => $this->plataforma->clientId(),
            'aud' => [$this->plataforma->issuer()],
            'iat' => $ahora,
            'exp' => $ahora + 600,
            'nonce' => bin2hex(random_bytes(16)),
            'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => $pedido['despliegue'],
            'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiDeepLinkingResponse',
            'https://purl.imsglobal.org/spec/lti/claim/version' => '1.3.0',
            'https://purl.imsglobal.org/spec/lti-dl/claim/content_items' => $elementos,
        ];
        if (null !== $pedido['data']) {
            $datos['https://purl.imsglobal.org/spec/lti-dl/claim/data'] = $pedido['data'];
        }

        return JWT::encode($datos, $this->llaves->privada(), 'RS256', $this->llaves->kid());
    }
}
