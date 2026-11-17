<?php

declare(strict_types=1);

namespace App\Captcha;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Captcha propio de «prueba de trabajo» (mismo esquema que ALTCHA), sin servicios externos:
 * el servidor elige un número secreto y entrega sha256(sal + número) firmado con HMAC;
 * el navegador prueba números hasta dar con él (1-2 s) y lo devuelve. No guarda datos de la
 * persona, funciona sin internet y cada desafío sirve una sola vez y por tiempo limitado.
 */
final class Captcha
{
    public const string ALGORITMO = 'SHA-256';
    private const int VIGENCIA = 1800;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secreto,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $usados,
        #[Autowire('%app.captcha_maximo%')]
        private readonly int $maximo = 250000,
    ) {
    }

    /** @return array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string} */
    public function desafio(): array
    {
        $sal = bin2hex(random_bytes(12)).'?expira='.(time() + self::VIGENCIA);
        $desafio = hash('sha256', $sal.random_int(0, $this->maximo));

        return [
            'algorithm' => self::ALGORITMO,
            'challenge' => $desafio,
            'maxnumber' => $this->maximo,
            'salt' => $sal,
            'signature' => $this->firmar($desafio),
        ];
    }

    /** Comprueba la respuesta (JSON en base64 que arma el navegador) y la marca como usada. */
    public function verificar(?string $respuesta): bool
    {
        $datos = json_decode((string) base64_decode((string) $respuesta, true), true);
        if (!\is_array($datos)) {
            return false;
        }
        $desafio = $datos['challenge'] ?? null;
        $sal = $datos['salt'] ?? null;
        $numero = $datos['number'] ?? null;
        $firma = $datos['signature'] ?? null;
        if (!\is_string($desafio) || !\is_string($sal) || !\is_int($numero) || !\is_string($firma)
            || self::ALGORITMO !== ($datos['algorithm'] ?? null)
            || $numero < 0 || $numero > $this->maximo
            || !hash_equals($this->firmar($desafio), $firma)
            || !hash_equals($desafio, hash('sha256', $sal.$numero))
            || 1 !== preg_match('/\?expira=(\d+)$/', $sal, $expira) || (int) $expira[1] < time()) {
            return false;
        }

        $usado = $this->usados->getItem('captcha_'.$desafio);
        if ($usado->isHit()) {
            return false;
        }
        $this->usados->save($usado->set(true)->expiresAt(new \DateTimeImmutable('@'.$expira[1])));

        return true;
    }

    /** Resuelve un desafío por fuerza bruta (lo que hace el navegador); útil en pruebas. */
    public static function resolver(array $desafio): string
    {
        for ($numero = 0; $numero <= $desafio['maxnumber']; ++$numero) {
            if (hash('sha256', $desafio['salt'].$numero) === $desafio['challenge']) {
                return base64_encode((string) json_encode(['number' => $numero] + $desafio));
            }
        }
        throw new \RuntimeException('Desafío sin solución.');
    }

    private function firmar(string $desafio): string
    {
        return hash_hmac('sha256', 'captcha|'.$desafio, $this->secreto);
    }
}
