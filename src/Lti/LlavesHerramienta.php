<?php

declare(strict_types=1);

namespace App\Lti;

use App\Configuracion\Ajustes;

/**
 * Par de llaves RSA de esta herramienta: firma las respuestas de vinculación (Deep Linking) y Moodle las verifica con
 * la parte pública publicada en /lti/jwks. La llave privada se genera la primera vez y se guarda cifrada en los ajustes.
 */
final class LlavesHerramienta
{
    private const CLAVE = 'LTI_LLAVE_PRIVADA';

    public function __construct(private readonly Ajustes $ajustes)
    {
    }

    public function privada(): string
    {
        $pem = $this->ajustes->valor(self::CLAVE);
        if (null !== $pem && false !== openssl_pkey_get_private($pem)) {
            return $pem;
        }
        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (false === $llave || !openssl_pkey_export($llave, $pem)) {
            throw new \RuntimeException('No se pudo generar la llave LTI (OpenSSL).');
        }
        $this->ajustes->guardar(self::CLAVE, $pem);

        return $pem;
    }

    /** Identificador de la llave (kid): cambia si la llave cambia. */
    public function kid(): string
    {
        return substr(hash('sha256', $this->detalles()['key']), 0, 16);
    }

    /** @return array{keys: list<array<string, string>>} */
    public function jwks(): array
    {
        $rsa = $this->detalles()['rsa'];

        return ['keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $this->kid(),
            'n' => self::base64url($rsa['n']),
            'e' => self::base64url($rsa['e']),
        ]]];
    }

    /** @return array{key: string, rsa: array{n: string, e: string}} */
    private function detalles(): array
    {
        $llave = openssl_pkey_get_private($this->privada());
        $detalles = false !== $llave ? openssl_pkey_get_details($llave) : false;
        if (false === $detalles) {
            throw new \RuntimeException('No se pudo leer la llave LTI.');
        }

        return $detalles;
    }

    private static function base64url(string $binario): string
    {
        return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
    }
}
