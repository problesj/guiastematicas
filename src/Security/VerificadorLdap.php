<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\Exception\LdapException;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Verifica credenciales con el LDAP institucional. Admite dos modos, según las variables de entorno:
 *
 * 1. Enlace directo: LDAP_DN_USUARIO con el patrón del DN, p. ej. "uid={usuario},ou=people,dc=ucn,dc=cl"
 *    o "{usuario}@ucn.cl" en Active Directory.
 * 2. Búsqueda y enlace: sin LDAP_DN_USUARIO, se busca el DN con LDAP_FILTRO bajo LDAP_BASE_DN, usando la cuenta
 *    de servicio LDAP_BIND_DN / LDAP_BIND_PASSWORD (o enlace anónimo si están vacías), y luego se enlaza con ese DN.
 *
 * Solo verifica la contraseña: la persona debe existir antes en Guías temáticas con su usuario LDAP.
 */
final class VerificadorLdap implements VerificadorLdapInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:default::LDAP_URL)%')]
        private readonly string $url = '',
        #[Autowire('%env(string:default::LDAP_CIFRADO)%')]
        private readonly string $cifrado = '',
        #[Autowire('%env(string:default::LDAP_DN_USUARIO)%')]
        private readonly string $patronDn = '',
        #[Autowire('%env(string:default::LDAP_BASE_DN)%')]
        private readonly string $baseDn = '',
        #[Autowire('%env(string:default::LDAP_FILTRO)%')]
        private readonly string $filtro = '',
        #[Autowire('%env(string:default::LDAP_BIND_DN)%')]
        private readonly string $bindDn = '',
        #[Autowire('%env(string:default::LDAP_BIND_PASSWORD)%')]
        private readonly string $bindPassword = '',
    ) {
    }

    public function estaHabilitado(): bool
    {
        return '' !== trim($this->url);
    }

    public function verificar(string $usuario, string $contrasena): bool
    {
        // Una contraseña vacía produce un enlace anónimo que el servidor acepta: nunca debe darse por válida.
        if (!$this->estaHabilitado() || '' === $usuario || '' === $contrasena) {
            return false;
        }

        try {
            $ldap = Ldap::create('ext_ldap', [
                'connection_string' => $this->url,
                'encryption' => \in_array($this->cifrado, ['ssl', 'tls'], true) ? $this->cifrado : 'none',
                'options' => ['protocol_version' => 3, 'referrals' => false, 'network_timeout' => 5],
            ]);

            $dn = '' !== $this->patronDn
                ? str_replace('{usuario}', $ldap->escape($usuario, '', LdapInterface::ESCAPE_DN), $this->patronDn)
                : $this->buscarDn($ldap, $usuario);

            if (null === $dn) {
                return false;
            }

            $ldap->bind($dn, $contrasena);

            return true;
        } catch (ConnectionException) {
            return false;
        } catch (LdapException $e) {
            $this->logger->error('Error al consultar LDAP: {mensaje}', ['mensaje' => $e->getMessage()]);

            return false;
        }
    }

    private function buscarDn(Ldap $ldap, string $usuario): ?string
    {
        $ldap->bind('' !== $this->bindDn ? $this->bindDn : null, '' !== $this->bindPassword ? $this->bindPassword : null);
        $filtro = str_replace(
            '{usuario}',
            $ldap->escape($usuario, '', LdapInterface::ESCAPE_FILTER),
            '' !== $this->filtro ? $this->filtro : '(uid={usuario})',
        );
        $resultados = $ldap->query($this->baseDn, $filtro, ['maxItems' => 2, 'filter' => ['dn']])->execute();

        return 1 === \count($resultados) ? $resultados[0]->getDn() : null;
    }
}
