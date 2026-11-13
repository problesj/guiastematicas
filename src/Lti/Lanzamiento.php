<?php

declare(strict_types=1);

namespace App\Lti;

/** Mensaje LTI 1.3 ya verificado (firma, emisor, destinatario, nonce y despliegue). */
final class Lanzamiento
{
    public const string RESOURCE_LINK = 'LtiResourceLinkRequest';
    public const string DEEP_LINKING = 'LtiDeepLinkingRequest';

    private const CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/';

    /** @param array<string, mixed> $claims */
    public function __construct(public readonly array $claims)
    {
    }

    public function tipo(): string
    {
        return (string) ($this->claims[self::CLAIM.'message_type'] ?? '');
    }

    public function despliegue(): string
    {
        return (string) ($this->claims[self::CLAIM.'deployment_id'] ?? '');
    }

    /** Parámetro personalizado (custom) del enlace, p. ej. «guia». */
    public function personalizado(string $nombre): ?string
    {
        $valor = ((array) ($this->claims[self::CLAIM.'custom'] ?? []))[$nombre] ?? null;

        return \is_scalar($valor) && '' !== (string) $valor ? (string) $valor : null;
    }

    public function curso(): ?string
    {
        $contexto = (array) ($this->claims[self::CLAIM.'context'] ?? []);
        $titulo = $contexto['title'] ?? $contexto['label'] ?? null;

        return \is_string($titulo) && '' !== $titulo ? $titulo : null;
    }

    public function idioma(): string
    {
        $locale = (string) (((array) ($this->claims[self::CLAIM.'launch_presentation'] ?? []))['locale'] ?? '');

        return str_starts_with(strtolower($locale), 'en') ? 'en' : 'es';
    }

    /** @return array{deep_link_return_url?: string, accept_types?: list<string>, data?: string, accept_multiple?: bool} */
    public function ajustesVinculo(): array
    {
        return (array) ($this->claims['https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings'] ?? []);
    }
}
