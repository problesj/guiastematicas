<?php

declare(strict_types=1);

namespace App\Servicio;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Metadatos de una publicación a partir de su DOI, desde doi.org (negociación de contenido en formato CSL JSON,
 * que entregan Crossref, DataCite y otras agencias). No necesita clave. Se guardan en caché 30 días.
 */
final class MetadatosDoi
{
    private const ESPERA = 8.0; // segundos

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(service: 'cache.catalogo')]
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** "https://doi.org/10.1000/xyz", "doi:10.1000/xyz" o "10.1000/xyz" → "10.1000/xyz"; null si no es un DOI. */
    public static function normalizar(?string $texto): ?string
    {
        $doi = trim((string) preg_replace('~^\s*(?:https?://(?:dx\.)?doi\.org/|doi:\s*)~i', '', (string) $texto));

        return 1 === preg_match('~^10\.\d{4,9}/\S+$~', $doi) ? $doi : null;
    }

    /**
     * @return array{titulo: string, autor: ?string, editorial: ?string, anio: ?string, isbn: ?string}|null
     */
    public function datos(string $doi): ?array
    {
        $doi = self::normalizar($doi);
        if (null === $doi) {
            return null;
        }

        $datos = $this->cache->get('doi-'.md5(strtolower($doi)), function (ItemInterface $item) use ($doi): array {
            $item->expiresAfter(30 * 86400);
            try {
                $respuesta = $this->http->request('GET', 'https://doi.org/'.implode('/', array_map('rawurlencode', explode('/', $doi))), [
                    'headers' => ['Accept' => 'application/vnd.citationstyles.csl+json'],
                    'timeout' => self::ESPERA,
                    'max_duration' => self::ESPERA,
                ]);
                if (404 === $respuesta->getStatusCode()) {
                    return []; // DOI inexistente
                }
                $csl = $respuesta->toArray();
            } catch (\Throwable $e) {
                $this->logger->warning('doi.org no respondió para {doi}: {error}', ['doi' => $doi, 'error' => $e::class]);
                $item->expiresAfter(600);

                return [];
            }

            return self::desdeCsl($csl);
        });

        return [] === $datos ? null : $datos;
    }

    /**
     * @param array<string, mixed> $csl
     *
     * @return array{titulo: string, autor: ?string, editorial: ?string, anio: ?string, isbn: ?string}|array{}
     */
    private static function desdeCsl(array $csl): array
    {
        $texto = function (mixed $valor): ?string {
            $valor = \is_array($valor) ? ($valor[0] ?? null) : $valor;
            $valor = \is_string($valor) ? trim(html_entity_decode(strip_tags($valor), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')) : '';

            return '' === $valor ? null : mb_substr((string) preg_replace('/\s+/', ' ', $valor), 0, 300);
        };
        $titulo = $texto($csl['title'] ?? null);
        if (null === $titulo) {
            return [];
        }
        if (null !== ($subtitulo = $texto($csl['subtitle'] ?? null))) {
            $titulo = mb_substr($titulo.': '.$subtitulo, 0, 300);
        }

        // Autores ("Apellido, Nombre"); si no hay, los editores. Más de tres se resumen con "et al.".
        $personas = [];
        foreach (['author', 'editor'] as $rol) {
            foreach ((array) ($csl[$rol] ?? []) as $p) {
                if (\is_array($p)) {
                    $nombre = $p['literal'] ?? trim(($p['family'] ?? '').(isset($p['given']) ? ', '.$p['given'] : ''), ' ,');
                    if (\is_string($nombre) && '' !== $nombre) {
                        $personas[] = $nombre;
                    }
                }
            }
            if ([] !== $personas) {
                break;
            }
        }
        $autor = match (true) {
            [] === $personas => null,
            \count($personas) > 3 => $personas[0].' et al.',
            default => implode('; ', $personas),
        };

        $anio = null;
        foreach (['issued', 'published-print', 'published-online', 'created'] as $campo) {
            $parte = $csl[$campo]['date-parts'][0][0] ?? null;
            if (is_numeric($parte)) {
                $anio = (string) (int) $parte;
                break;
            }
        }

        $isbn = null;
        foreach ((array) ($csl['ISBN'] ?? []) as $candidato) {
            $limpio = preg_replace('/[^0-9X]/', '', strtoupper((string) $candidato)) ?? '';
            if (13 === \strlen($limpio)) { // el primero, que suele ser el de la edición impresa
                $isbn = $limpio;
                break;
            }
            if (10 === \strlen($limpio)) {
                $isbn ??= $limpio;
            }
        }

        return [
            'titulo' => $titulo,
            'autor' => null === $autor ? null : mb_substr($autor, 0, 200),
            'editorial' => $texto($csl['publisher'] ?? null),
            'anio' => $anio,
            'isbn' => $isbn,
        ];
    }
}
