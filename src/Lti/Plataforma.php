<?php

declare(strict_types=1);

namespace App\Lti;

use App\Configuracion\Ajustes;

/**
 * La plataforma LTI 1.3 registrada (el Moodle de la UCN): datos que entrega su administrador al registrar la
 * herramienta. Se definen en Administración → Integraciones o, si no, en el .env.local del servidor.
 */
final class Plataforma
{
    public function __construct(private readonly Ajustes $ajustes)
    {
    }

    public function configurada(): bool
    {
        return '' !== $this->issuer() && '' !== $this->clientId() && '' !== $this->authUrl() && '' !== $this->jwksUrl();
    }

    public function issuer(): string
    {
        return rtrim($this->valor('LTI_ISSUER'), '/');
    }

    public function clientId(): string
    {
        return $this->valor('LTI_CLIENT_ID');
    }

    /** @return list<string> despliegues aceptados (vacío: cualquiera de esta plataforma) */
    public function despliegues(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->valor('LTI_DEPLOYMENT_ID')))));
    }

    public function authUrl(): string
    {
        return $this->valor('LTI_AUTH_URL');
    }

    public function jwksUrl(): string
    {
        return $this->valor('LTI_JWKS_URL');
    }

    /** Origen (esquema://host[:puerto]) de la plataforma, para permitir que muestre la herramienta en un iframe. */
    public function origen(): ?string
    {
        $partes = parse_url($this->issuer());
        if (!isset($partes['scheme'], $partes['host'])) {
            return null;
        }

        return $partes['scheme'].'://'.$partes['host'].(isset($partes['port']) ? ':'.$partes['port'] : '');
    }

    /** El issuer puede venir con o sin barra final. */
    public function esIssuer(mixed $iss): bool
    {
        return \is_string($iss) && '' !== $this->issuer() && rtrim($iss, '/') === $this->issuer();
    }

    private function valor(string $clave): string
    {
        return trim((string) ($this->ajustes->valor($clave) ?? $_SERVER[$clave] ?? $_ENV[$clave] ?? ''));
    }
}
