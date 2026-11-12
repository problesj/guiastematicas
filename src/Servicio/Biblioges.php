<?php

declare(strict_types=1);

namespace App\Servicio;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Consulta la API de Biblioges (bibliografías de las carreras y asignaturas de la UCN) para agregar libros a un bloque.
 *
 * La API es de solo lectura y pide una clave (cabecera X-API-Key) que genera un administrador de Biblioges en
 * Administración → API Keys. La clave va solo en el .env.local del servidor (BIBLIOGES_API_KEY). Sin ella, o si
 * Biblioges no responde, el buscador no se ofrece o informa el error y el bloque se edita igual.
 *
 * Las bibliografías se entregan en un formato común para el bloque Libros, agrupadas por asignatura:
 * - declaradas: las que el programa de la asignatura declara (título, autores, editorial, año, ISBN, DOI, URL);
 * - disponibles: los ejemplares que la biblioteca tiene de cada declarada (MMS de Alma, enlace de acceso), todas las
 *   ediciones o solo la última (mayor año de edición).
 */
final class Biblioges
{
    public const TIPOS = ['todas', 'basica', 'complementaria', 'otro'];
    public const FUENTES = ['declaradas', 'disponibles'];
    private const ESPERA = 10.0; // segundos

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(service: 'cache.catalogo')]
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:default::BIBLIOGES_API_URL)%')]
        private readonly string $apiUrl = '',
        #[Autowire('%env(string:default::BIBLIOGES_API_KEY)%')]
        private readonly string $clave = '',
    ) {
    }

    public function disponible(): bool
    {
        return '' !== $this->apiUrl && '' !== $this->clave;
    }

    /**
     * Planes de estudio vigentes, para elegir la carrera (caché de un día).
     *
     * @return list<array{codigo: string, nombre: string, sede: ?string}>
     */
    public function carreras(): array
    {
        if (!$this->disponible()) {
            return [];
        }

        return $this->cache->get('biblioges-carreras', function (ItemInterface $item): array {
            $item->expiresAfter(86400);
            $datos = $this->consultar('/carreras', ['solo_vigentes' => 'true']);
            if (null === $datos) {
                $item->expiresAfter(300);

                return [];
            }
            $carreras = [];
            foreach ((array) ($datos['carreras'] ?? []) as $plan) {
                $codigo = \is_array($plan) ? trim((string) ($plan['codigo'] ?? '')) : '';
                if ('' !== $codigo) {
                    $carreras[$codigo] ??= ['codigo' => $codigo, 'nombre' => trim((string) ($plan['nombre'] ?? '')), 'sede' => self::texto($plan['sede']['nombre'] ?? null)];
                }
            }
            usort($carreras, fn (array $a, array $b) => [$a['nombre'], $a['codigo']] <=> [$b['nombre'], $b['codigo']]);

            return $carreras;
        });
    }

    /**
     * Bibliografías de una carrera o asignatura, agrupadas por asignatura y listas para el bloque Libros.
     *
     * @return array{titulo: string, grupos: list<array{nombre: string, libros: list<array<string, mixed>>}>}|array{error: string}
     */
    public function bibliografias(string $modo, string $codigo, string $tipo = 'todas', string $fuente = 'declaradas', bool $ultimaEdicion = false): array
    {
        $codigo = trim($codigo);
        if (!$this->disponible() || !\in_array($modo, ['carrera', 'asignatura'], true) || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/', $codigo)) {
            return ['error' => 'parametros'];
        }
        $tipo = \in_array($tipo, self::TIPOS, true) ? $tipo : 'todas';
        $fuente = \in_array($fuente, self::FUENTES, true) ? $fuente : 'declaradas';
        $parametros = ['tipo_bibliografia' => $tipo] + ('disponibles' === $fuente ? ['ediciones' => $ultimaEdicion ? 'ultima' : 'todas'] : []);
        $ruta = \sprintf('/%s/%s/bibliografias-%s', 'carrera' === $modo ? 'carreras' : 'asignaturas', rawurlencode($codigo), $fuente);

        return $this->cache->get('biblioges-'.md5($ruta.'|'.http_build_query($parametros)), function (ItemInterface $item) use ($ruta, $parametros, $modo, $fuente): array {
            $item->expiresAfter(3600);
            $estado = 0;
            $datos = $this->consultar($ruta, $parametros, $estado);
            if (null === $datos) {
                $item->expiresAfter(60);

                return ['error' => match ($estado) { 404 => 'no_encontrado', 401, 403 => 'no_autorizado', default => 'sin_respuesta' }];
            }

            if ('carrera' === $modo) {
                $titulo = trim(($datos['carrera']['codigo'] ?? '').' '.($datos['carrera']['nombre'] ?? ''));
                $grupos = [];
                foreach ((array) ($datos['asignaturas'] ?? []) as $asignatura) {
                    $libros = self::libros((array) ($asignatura['bibliografias'] ?? []), $fuente);
                    if ([] !== $libros) {
                        $grupos[] = ['nombre' => trim(($asignatura['codigo'] ?? '').' '.($asignatura['nombre'] ?? '')), 'libros' => $libros];
                    }
                }
            } else {
                $titulo = trim(($datos['asignatura']['codigo'] ?? '').' '.($datos['asignatura']['nombre'] ?? ''));
                $libros = self::libros((array) ($datos['bibliografias'] ?? []), $fuente);
                $grupos = [] === $libros ? [] : [['nombre' => $titulo, 'libros' => $libros]];
            }

            return ['titulo' => $titulo, 'grupos' => $grupos];
        });
    }

    /**
     * Declaradas (o sus disponibles) en el formato de un libro del bloque.
     *
     * @param list<mixed> $declaradas
     *
     * @return list<array<string, mixed>>
     */
    private static function libros(array $declaradas, string $fuente): array
    {
        $libros = [];
        foreach ($declaradas as $declarada) {
            if (!\is_array($declarada)) {
                continue;
            }
            $base = [
                'tipo_bibliografia' => self::texto($declarada['tipo_bibliografia'] ?? null),
                'tipo' => self::texto($declarada['tipo'] ?? null),
                'isbn' => self::isbn($declarada['isbn'] ?? null),
                'doi' => self::texto($declarada['doi'] ?? null),
            ];
            if ('declaradas' === $fuente) {
                $libros[] = $base + [
                    'titulo' => self::texto($declarada['titulo'] ?? null),
                    'autor' => self::autores($declarada['autores'] ?? null),
                    'editorial' => self::texto($declarada['editorial'] ?? null),
                    'anio' => self::anio($declarada['anio_publicacion'] ?? null),
                    'enlace' => self::url($declarada['url'] ?? null),
                    'mms' => null,
                ];
                continue;
            }
            $disponibles = array_values(array_filter((array) ($declarada['disponibles'] ?? []), 'is_array'));
            // El ISBN es de la edición declarada: solo se aplica si la biblioteca tiene una sola edición de ella.
            if (\count($disponibles) > 1) {
                $base['isbn'] = null;
            }
            foreach ($disponibles as $disponible) {
                $libros[] = $base + [
                    'titulo' => self::texto($disponible['titulo'] ?? null) ?? self::texto($declarada['titulo'] ?? null),
                    'autor' => self::autores($disponible['autores'] ?? null) ?? self::autores($declarada['autores'] ?? null),
                    'editorial' => self::texto($disponible['editorial'] ?? null),
                    'anio' => self::anio($disponible['anio_edicion'] ?? null),
                    'enlace' => self::url($disponible['url_acceso'] ?? null),
                    'mms' => self::texto($disponible['id_mms'] ?? null),
                ];
            }
        }

        return array_values(array_filter($libros, fn (array $l) => null !== $l['titulo']));
    }

    private static function autores(mixed $autores): ?string
    {
        $nombres = array_filter(array_map(fn ($a) => self::texto($a), \is_array($autores) ? $autores : [$autores]));

        return [] === $nombres ? null : mb_substr(implode('; ', $nombres), 0, 200);
    }

    private static function anio(mixed $anio): ?string
    {
        return \is_int($anio) || (\is_string($anio) && 1 === preg_match('/^\d{4}$/', $anio)) ? (string) $anio : null;
    }

    private static function isbn(mixed $isbn): ?string
    {
        $isbn = strtoupper((string) preg_replace('/[^0-9Xx]/', '', explode(' ', trim((string) (\is_scalar($isbn) ? $isbn : '')))[0]));

        return 1 === preg_match('/^(\d{13}|\d{9}[\dX])$/', $isbn) ? $isbn : null;
    }

    private static function url(mixed $url): ?string
    {
        $url = self::texto($url);

        return null !== $url && 1 === preg_match('~^https?://~i', $url) ? mb_substr($url, 0, 500) : null;
    }

    private static function texto(mixed $valor): ?string
    {
        $valor = \is_scalar($valor) ? trim((string) $valor) : '';

        return '' === $valor ? null : mb_substr($valor, 0, 300);
    }

    /**
     * @param array<string, scalar> $parametros
     *
     * @return array<string, mixed>|null null si Biblioges no responde o responde con error
     */
    private function consultar(string $ruta, array $parametros, int &$estado = 0): ?array
    {
        try {
            $respuesta = $this->http->request('GET', rtrim($this->apiUrl, '/').$ruta, [
                'query' => $parametros,
                'headers' => ['Accept' => 'application/json', 'X-API-Key' => $this->clave],
                'timeout' => self::ESPERA,
                'max_duration' => self::ESPERA,
            ]);
            $estado = $respuesta->getStatusCode();
            if (200 !== $estado) {
                $this->logger->warning('Biblioges respondió {estado} a {ruta}', ['ruta' => $ruta, 'estado' => $estado]);

                return null;
            }

            return $respuesta->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('Biblioges no respondió a {ruta}: {error}', ['ruta' => $ruta, 'error' => $e::class]);

            return null;
        }
    }
}
