<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Security\VerificadorLdapInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Qué métodos de inicio de sesión están configurados. Disponible en Twig como "metodos_acceso". */
final class MetodosAcceso
{
    public function __construct(
        private readonly VerificadorLdapInterface $ldap,
        #[Autowire('%env(string:default::GOOGLE_CLIENT_ID)%')]
        private readonly string $googleClientId = '',
    ) {
    }

    public function ldap(): bool
    {
        return $this->ldap->estaHabilitado();
    }

    public function google(): bool
    {
        return '' !== trim($this->googleClientId);
    }
}
