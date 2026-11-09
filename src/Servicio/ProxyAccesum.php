<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Entity\Recurso;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Arma el enlace público de un recurso pasando por el proxy AccesUM cuando corresponde.
 *
 * PROXY_URL admite dos formas:
 * - prefijo, p. ej. "https://accesum.ucn.cl/login?url=" → se agrega la URL original tal cual;
 * - plantilla con {url}, p. ej. "https://proxy.ucn.cl/login?qurl={url}" → se reemplaza por la URL codificada.
 * Si PROXY_URL está vacío, se usa siempre la URL original.
 */
final class ProxyAccesum
{
    public function __construct(
        #[Autowire('%env(string:default::PROXY_URL)%')]
        private readonly string $proxy = '',
    ) {
    }

    public function estaConfigurado(): bool
    {
        return '' !== trim($this->proxy);
    }

    public function enlace(Recurso $recurso): string
    {
        return $recurso->isRequiereProxy() ? $this->aplicar($recurso->getUrl()) : $recurso->getUrl();
    }

    public function aplicar(string $url): string
    {
        $proxy = trim($this->proxy);
        if ('' === $proxy || $this->tieneProxy($url)) {
            return $url;
        }

        return str_contains($proxy, '{url}') ? str_replace('{url}', rawurlencode($url), $proxy) : $proxy.$url;
    }

    /**
     * Si la URL ya trae el prefijo del proxy (frecuente en listas en Excel), devuelve la URL original;
     * si no, null.
     */
    public function quitar(string $url): ?string
    {
        $proxy = trim($this->proxy);
        if ('' === $proxy) {
            return null;
        }

        if (str_contains($proxy, '{url}')) {
            [$antes, $despues] = explode('{url}', $proxy, 2);
            if (str_starts_with($url, $antes) && ('' === $despues || str_ends_with($url, $despues))) {
                return rawurldecode(substr($url, \strlen($antes), \strlen($url) - \strlen($antes) - \strlen($despues)));
            }

            return null;
        }

        return str_starts_with($url, $proxy) ? substr($url, \strlen($proxy)) : null;
    }

    private function tieneProxy(string $url): bool
    {
        return null !== $this->quitar($url);
    }
}
