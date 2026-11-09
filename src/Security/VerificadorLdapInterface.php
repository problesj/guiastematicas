<?php

declare(strict_types=1);

namespace App\Security;

interface VerificadorLdapInterface
{
    /** Indica si hay un servidor LDAP configurado (LDAP_URL no vacío). */
    public function estaHabilitado(): bool;

    /** Comprueba usuario y contraseña contra el directorio LDAP de la UCN. */
    public function verificar(string $usuario, string $contrasena): bool;
}
