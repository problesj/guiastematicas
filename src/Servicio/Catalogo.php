<?php

declare(strict_types=1);

namespace App\Servicio;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Consulta el catálogo de la biblioteca (Ex Libris):
 * - Primo VE (API de búsqueda) para encontrar un libro por ISBN y su registro en el descubridor;
 * - Alma (API de registros bibliográficos) para saber si los ejemplares están disponibles.
 *
 * Las claves (PRIMO_API_KEY, ALMA_API_KEY) solo están en el .env.local del servidor. Sin ellas, o si el catálogo no
 * responde, los métodos devuelven null y la guía se muestra igual con los datos escritos a mano.
 */
final class Catalogo
{
    private const ESPERA = 6.0; // segundos

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(service: 'cache.catalogo')]
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:default::EXLIBRIS_API_URL)%')]
        private readonly string $apiUrl = '',
        #[Autowire('%env(string:default::PRIMO_API_KEY)%')]
        private readonly string $primoClave = '',
        #[Autowire('%env(string:default::ALMA_API_KEY)%')]
        private readonly string $almaClave = '',
        #[Autowire('%env(string:default::PRIMO_URL)%')]
        private readonly string $primoUrl = '',
        #[Autowire('%env(string:default::PRIMO_VID)%')]
        private readonly string $primoVid = '',
    ) {
    }

    public function buscaPorIsbn(): bool
    {
        return '' !== $this->apiUrl && '' !== $this->primoClave && '' !== $this->primoVid;
    }

    #[AsTwigFunction('catalogo_informa_disponibilidad')]
    public function informaDisponibilidad(): bool
    {
        return '' !== $this->apiUrl && '' !== $this->almaClave;
    }

    /**
     * Datos de un libro del catálogo a partir de su ISBN (se guardan en caché una semana).
     *
     * @return array{titulo: string, autor: ?string, editorial: ?string, anio: ?string, registro: string, mms: ?string}|null
     */
    public function libroPorIsbn(string $isbn): ?array
    {
        $isbn = preg_replace('/[^0-9X]/', '', strtoupper($isbn)) ?? '';
        if (!$this->buscaPorIsbn() || !\in_array(\strlen($isbn), [10, 13], true)) {
            return null;
        }

        $libro = $this->cache->get('isbn-'.$isbn, function (ItemInterface $item) use ($isbn): array {
            $item->expiresAfter(7 * 86400);
            $docs = $this->consultar('/primo/v1/search', [
                'vid' => $this->primoVid,
                'tab' => 'Everything',
                'scope' => 'MyInst_and_CI',
                'q' => 'isbn,exact,'.$isbn,
                'limit' => 5,
                'apikey' => $this->primoClave,
            ])['docs'] ?? null;
            if (!\is_array($docs)) {
                $item->expiresAfter(600); // error del catálogo: se reintenta pronto

                return [];
            }

            // Primero los registros propios de la biblioteca (contexto L), que tienen ejemplares en Alma.
            usort($docs, fn ($a, $b) => (($b['context'] ?? '') === 'L') <=> (($a['context'] ?? '') === 'L'));

            return [] === $docs ? [] : self::datosDeRegistro($docs[0]);
        });

        return [] === $libro ? null : $libro;
    }

    /** Dirección del registro completo en Primo VE. */
    #[AsTwigFunction('enlace_registro_catalogo')]
    public function enlaceRegistro(string $registro, string $idioma): ?string
    {
        if ('' === $this->primoUrl || '' === $this->primoVid || '' === $registro) {
            return null;
        }

        return rtrim($this->primoUrl, '/').'/discovery/fulldisplay?'.strtr(http_build_query([
            'docid' => $registro,
            'context' => str_starts_with($registro, 'alma') ? 'L' : 'PC',
            'vid' => $this->primoVid,
            'lang' => $idioma,
        ], '', '&', \PHP_QUERY_RFC3986), ['%3A' => ':']);
    }

    /**
     * Disponibilidad de varios registros de Alma (MMS id), en una consulta y con caché de 30 minutos.
     *
     * @param list<string> $mms
     *
     * @return array<string, array{disponible: bool, biblioteca: ?string, signatura: ?string}> por MMS id; los que Alma no conoce no aparecen
     */
    public function disponibilidad(array $mms): array
    {
        $mms = array_values(array_unique(array_filter($mms, fn ($id) => \is_string($id) && preg_match('/^\d{6,25}$/', $id))));
        if (!$this->informaDisponibilidad() || [] === $mms) {
            return [];
        }
        sort($mms);

        return $this->cache->get('disponibilidad-'.md5(implode(',', \array_slice($mms, 0, 100))), function (ItemInterface $item) use ($mms): array {
            $item->expiresAfter(1800);
            $respuesta = $this->consultar('/almaws/v1/bibs', [
                'mms_id' => implode(',', \array_slice($mms, 0, 100)),
                'view' => 'brief',
                'expand' => 'p_avail',
                'format' => 'json',
                'apikey' => $this->almaClave,
            ]);
            if (null === $respuesta) {
                $item->expiresAfter(300);

                return [];
            }
            $resultado = [];
            foreach ((array) ($respuesta['bib'] ?? []) as $bib) {
                if (\is_array($bib) && isset($bib['mms_id'])) {
                    $resultado[(string) $bib['mms_id']] = self::ejemplares($bib);
                }
            }

            return $resultado;
        });
    }

    /**
     * @param array<string, mixed> $doc registro de la API de búsqueda de Primo
     *
     * @return array{titulo: string, autor: ?string, editorial: ?string, anio: ?string, registro: string, mms: ?string}|array{}
     */
    private static function datosDeRegistro(array $doc): array
    {
        $valor = function (string ...$rutas) use ($doc): ?string {
            foreach ($rutas as $ruta) {
                $dato = $doc['pnx'] ?? [];
                foreach (explode('.', $ruta) as $clave) {
                    $dato = \is_array($dato) ? ($dato[$clave] ?? null) : null;
                }
                $dato = \is_array($dato) ? ($dato[0] ?? null) : $dato;
                // Primo agrega subcampos ("Cormen, Thomas H.$$QCormen") y a veces marcas de enlace ("$$Q").
                $texto = \is_string($dato) ? trim(explode('$$', $dato)[0], " \t\n/:;,") : '';
                if ('' !== $texto) {
                    return mb_substr($texto, 0, 300);
                }
            }

            return null;
        };
        $titulo = $valor('display.title');
        $registro = $valor('control.recordid');
        if (null === $titulo || null === $registro) {
            return [];
        }
        $mms = $valor('display.mms') ?? (preg_match('/^alma(\d+)$/', $registro, $m) ? $m[1] : null);
        preg_match('/\d{4}/', (string) $valor('display.creationdate', 'addata.date'), $anio);

        return [
            'titulo' => $titulo,
            'autor' => $valor('display.creator', 'display.contributor', 'addata.au'),
            'editorial' => $valor('display.publisher', 'addata.pub'),
            'anio' => $anio[0] ?? null,
            'registro' => $registro,
            'mms' => null !== $mms && preg_match('/^\d{6,25}$/', $mms) ? $mms : null,
        ];
    }

    /**
     * Resume los campos AVA (disponibilidad física) del MARC que Alma agrega con expand=p_avail.
     *
     * @param array<string, mixed> $bib
     *
     * @return array{disponible: bool, biblioteca: ?string, signatura: ?string}
     */
    private static function ejemplares(array $bib): array
    {
        $resumen = ['disponible' => false, 'biblioteca' => null, 'signatura' => null];
        foreach ((array) ($bib['anies'] ?? []) as $marc) {
            // Alma declara UTF-16 aunque el texto llega en UTF-8: se quita la declaración antes de leerlo.
            $xml = \is_string($marc) ? @simplexml_load_string(preg_replace('/^\s*<\?xml[^>]*\?>/', '', $marc) ?? '', options: \LIBXML_NONET) : false;
            if (false === $xml) {
                continue;
            }
            foreach ($xml->xpath('//datafield[@tag="AVA"]') ?: [] as $ava) {
                $sub = [];
                foreach ($ava->subfield as $s) {
                    $sub[(string) $s['code']] ??= trim((string) $s);
                }
                $disponible = 'available' === ($sub['e'] ?? '');
                // Se informa la primera biblioteca con ejemplares disponibles o, si no hay, la primera que aparezca.
                if (null === $resumen['biblioteca'] || ($disponible && !$resumen['disponible'])) {
                    $resumen = [
                        'disponible' => $disponible,
                        'biblioteca' => ($sub['q'] ?? $sub['b'] ?? '') ?: null,
                        'signatura' => ($sub['d'] ?? '') ?: null,
                    ];
                }
            }
        }

        return $resumen;
    }

    /**
     * @param array<string, scalar> $parametros
     *
     * @return array<string, mixed>|null null si el catálogo no responde o responde con error
     */
    private function consultar(string $ruta, array $parametros): ?array
    {
        try {
            $respuesta = $this->http->request('GET', rtrim($this->apiUrl, '/').$ruta, [
                'query' => $parametros,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::ESPERA,
                'max_duration' => self::ESPERA,
            ]);

            return $respuesta->toArray();
        } catch (\Throwable $e) {
            // El mensaje puede incluir la URL con la clave: se registra solo la ruta y el tipo de error.
            $this->logger->warning('El catálogo no respondió a {ruta}: {error}', ['ruta' => $ruta, 'error' => $e::class]);

            return null;
        }
    }
}
