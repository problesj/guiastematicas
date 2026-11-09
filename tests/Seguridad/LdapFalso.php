<?php

declare(strict_types=1);

namespace App\Tests\Seguridad;

use App\Security\VerificadorLdapInterface;

/** Sustituye al LDAP real en las pruebas: acepta un único par usuario/contraseña. */
final class LdapFalso implements VerificadorLdapInterface
{
    public function __construct(private readonly string $usuario, private readonly string $contrasena)
    {
    }

    public function estaHabilitado(): bool
    {
        return true;
    }

    public function verificar(string $usuario, string $contrasena): bool
    {
        return $usuario === $this->usuario && $contrasena === $this->contrasena;
    }
}
