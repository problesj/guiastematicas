<?php

declare(strict_types=1);

namespace App\Configuracion;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ajustes de las integraciones que el administrador define en el panel. Cada uno reemplaza a la variable de entorno del
 * mismo nombre (si no está en el panel, vale lo del .env.local del servidor). Los secretos se guardan cifrados con
 * sodium (XSalsa20-Poly1305) con una clave derivada de APP_SECRET: si APP_SECRET cambia, hay que volver a escribirlos.
 */
final class Ajustes implements ResetInterface
{
    /** Grupos del panel → ajustes → opciones (secreto: se cifra y nunca se muestra; opciones: lista cerrada). */
    public const GRUPOS = [
        'proxy' => [
            'PROXY_URL' => [],
        ],
        'catalogo' => [
            'PRIMO_URL' => [],
            'PRIMO_VID' => [],
            'EXLIBRIS_API_URL' => [],
            'PRIMO_API_KEY' => ['secreto' => true],
            'ALMA_API_KEY' => ['secreto' => true],
        ],
        'biblioges' => [
            'BIBLIOGES_API_URL' => [],
            'BIBLIOGES_API_KEY' => ['secreto' => true],
        ],
        'ldap' => [
            'LDAP_URL' => [],
            'LDAP_CIFRADO' => ['opciones' => ['none', 'ssl', 'tls']],
            'LDAP_DN_USUARIO' => [],
            'LDAP_BASE_DN' => [],
            'LDAP_FILTRO' => [],
            'LDAP_BIND_DN' => [],
            'LDAP_BIND_PASSWORD' => ['secreto' => true],
        ],
        'google' => [
            'GOOGLE_CLIENT_ID' => [],
            'GOOGLE_CLIENT_SECRET' => ['secreto' => true],
            'GOOGLE_DOMINIO' => [],
        ],
    ];

    /** Ajustes de apariencia (sin variable de entorno equivalente), ver App\Configuracion\Apariencia. */
    public const APARIENCIA = ['SITIO_TITULO', 'SITIO_TITULO_EN', 'SITIO_INSTITUCION', 'COLOR_PRINCIPAL', 'COLOR_BARRA', 'COLOR_PIE', 'LOGO'];

    /** @var array<string, string|false>|null valores guardados (false: secreto que no se pudo descifrar) */
    private ?array $valores = null;

    public function __construct(
        private readonly Connection $conexion,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $secreto,
    ) {
    }

    /** Ajuste de integración (reemplaza a una variable de entorno). */
    public static function existe(string $clave): bool
    {
        return null !== self::opciones($clave);
    }

    public static function esSecreto(string $clave): bool
    {
        return (bool) (self::opciones($clave)['secreto'] ?? false);
    }

    /** @return array{secreto?: bool, opciones?: list<string>}|null */
    public static function opciones(string $clave): ?array
    {
        foreach (self::GRUPOS as $ajustes) {
            if (isset($ajustes[$clave])) {
                return $ajustes[$clave];
            }
        }

        return null;
    }

    /** Valor definido en el panel, o null si no lo está (entonces rige la variable de entorno). */
    public function valor(string $clave): ?string
    {
        $valor = $this->cargar()[$clave] ?? null;

        return \is_string($valor) ? $valor : null;
    }

    public function enPanel(string $clave): bool
    {
        return \array_key_exists($clave, $this->cargar());
    }

    /** Secreto guardado que ya no se puede descifrar (APP_SECRET cambió). */
    public function ilegible(string $clave): bool
    {
        return false === ($this->cargar()[$clave] ?? null);
    }

    /** Guarda un ajuste; null lo borra del panel y vuelve a regir la variable de entorno. */
    public function guardar(string $clave, ?string $valor): void
    {
        if (!self::existe($clave) && !\in_array($clave, self::APARIENCIA, true)) {
            throw new \InvalidArgumentException(\sprintf('Ajuste desconocido: %s', $clave));
        }
        $this->conexion->delete('ajuste', ['clave' => $clave]);
        if (null !== $valor) {
            $secreto = self::esSecreto($clave);
            $this->conexion->insert('ajuste', [
                'clave' => $clave,
                'valor' => $secreto ? $this->cifrar($valor) : $valor,
                'cifrado' => $secreto ? 1 : 0,
                'actualizado_en' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
        $this->valores = null;
    }

    public function reset(): void
    {
        $this->valores = null;
    }

    /** @return array<string, string|false> */
    private function cargar(): array
    {
        if (null !== $this->valores) {
            return $this->valores;
        }
        $this->valores = [];
        try {
            $filas = $this->conexion->fetchAllAssociative('SELECT clave, valor, cifrado FROM ajuste');
        } catch (\Throwable) {
            return $this->valores; // sin base de datos o sin la tabla (instalación, migraciones): rige el entorno
        }
        foreach ($filas as $fila) {
            $valor = (string) $fila['valor'];
            $this->valores[(string) $fila['clave']] = (bool) $fila['cifrado'] ? $this->descifrar($valor, (string) $fila['clave']) : $valor;
        }

        return $this->valores;
    }

    private function llave(): string
    {
        return sodium_crypto_generichash('guias-tematicas/ajustes', sodium_crypto_generichash($this->secreto), \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private function cifrar(string $texto): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($texto, $nonce, $this->llave()));
    }

    private function descifrar(string $guardado, string $clave): string|false
    {
        $datos = base64_decode($guardado, true);
        $texto = false === $datos || \strlen($datos) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ? false : sodium_crypto_secretbox_open(
            substr($datos, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($datos, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->llave(),
        );
        if (false === $texto) {
            $this->logger->warning('No se pudo descifrar el ajuste {clave} (¿cambió APP_SECRET?).', ['clave' => $clave]);
        }

        return $texto;
    }
}
