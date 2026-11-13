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
 * - Primo VE (API de búsqueda) para encontrar un libro por ISBN, o varios por título, autor y editorial, y su registro
 *   en el descubridor;
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
        #[Autowire('%env(string:default::ajuste:EXLIBRIS_API_URL)%')]
        private readonly string $apiUrl = '',
        #[Autowire('%env(string:default::ajuste:PRIMO_API_KEY)%')]
        private readonly string $primoClave = '',
        #[Autowire('%env(string:default::ajuste:ALMA_API_KEY)%')]
        private readonly string $almaClave = '',
        #[Autowire('%env(string:default::ajuste:PRIMO_URL)%')]
        private readonly string $primoUrl = '',
        #[Autowire('%env(string:default::ajuste:PRIMO_VID)%')]
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

    /**
     * Libros del catálogo por título exacto, autor y/o editorial, para elegir cuáles agregar a un bloque (caché de una
     * hora). Primo no tiene un campo de búsqueda por editorial: se busca en todos los campos y se filtra por la
     * editorial del registro.
     *
     * @return array{total: int, libros: list<array{titulo: string, autor: ?string, editorial: ?string, anio: ?string, isbn: ?string, registro: string, mms: ?string, local: bool}>}|null null si no hay criterios o el catálogo no responde
     */
    public function buscarLibros(?string $titulo, ?string $autor, ?string $editorial, int $desde = 0, int $cantidad = 20): ?array
    {
        $criterios = array_filter([
            'title,exact' => self::criterio($titulo),
            'creator,contains' => self::criterio($autor),
            'any,contains' => self::criterio($editorial),
        ], fn (?string $v) => null !== $v);
        if (!$this->buscaPorIsbn() || [] === $criterios) {
            return null;
        }
        $q = implode(',AND;', array_map(fn (string $campo, string $valor) => $campo.','.$valor, array_keys($criterios), $criterios));
        $desde = max(0, min($desde, 1980));

        $resultado = $this->cache->get('buscar-'.md5($q.'|'.$desde.'|'.$cantidad), function (ItemInterface $item) use ($q, $desde, $cantidad): array {
            $item->expiresAfter(3600);
            $respuesta = $this->consultar('/primo/v1/search', [
                'vid' => $this->primoVid,
                'tab' => 'Everything',
                'scope' => 'MyInst_and_CI',
                'q' => $q,
                'qInclude' => 'facet_rtype,exact,books',
                'offset' => $desde,
                'limit' => $cantidad,
                'apikey' => $this->primoClave,
            ]);
            if (!\is_array($respuesta['docs'] ?? null)) {
                $item->expiresAfter(60); // error del catálogo: se reintenta pronto

                return [];
            }
            $libros = [];
            foreach ($respuesta['docs'] as $doc) {
                $libro = \is_array($doc) ? self::datosDeRegistro($doc) : [];
                if ([] !== $libro) {
                    $libros[] = $libro + ['isbn' => self::isbnDeRegistro($doc), 'local' => 'L' === ($doc['context'] ?? null)];
                }
            }

            return ['total' => (int) ($respuesta['info']['total'] ?? \count($libros)), 'libros' => $libros];
        });
        if ([] === $resultado) {
            return null;
        }
        $editorial = self::criterio($editorial);
        if (null !== $editorial) {
            $buscada = self::comparable($editorial);
            $resultado['libros'] = array_values(array_filter(
                $resultado['libros'],
                fn (array $l) => null !== $l['editorial'] && str_contains(self::comparable($l['editorial']), $buscada),
            ));
        }

        return $resultado;
    }

    /** Último error de la búsqueda de revistas (código HTTP y mensaje de Primo, sin la clave), para mostrarlo al editor. */
    private ?string $errorRevistas = null;

    public function errorRevistas(): ?string
    {
        return $this->errorRevistas;
    }

    /**
     * Revista del catálogo por ISSN (búsqueda de revistas de Primo VE), con caché de un día. Primero con
     * "issn,exact"; si Primo no la encuentra así, con "any,contains" y el ISSN, quedándose con la que lo tenga.
     *
     * @return array{titulo: string, issn: ?string, eissn: ?string, editorial: ?string, registro: string, mms: ?string}|null
     */
    public function revistaPorIssn(string $issn): ?array
    {
        $issn = self::issn($issn);
        if (null === $issn || !$this->buscaPorIsbn()) {
            return null;
        }
        $revista = $this->cache->get('issn-'.$issn, function (ItemInterface $item) use ($issn): array {
            $item->expiresAfter(86400);
            foreach (['issn,exact,'.$issn, 'any,contains,'.$issn] as $q) {
                $respuesta = $this->consultarRevistas(['q' => $q, 'offset' => 0, 'limit' => 10]);
                if (null === $respuesta) {
                    $item->expiresAfter(60);

                    return [];
                }
                foreach ($respuesta['docs'] as $doc) {
                    $revista = \is_array($doc) ? self::datosDeRevista($doc) : [];
                    if ([] !== $revista && \in_array($issn, [$revista['issn'], $revista['eissn']], true)) {
                        return $revista;
                    }
                }
            }

            return [];
        });

        return [] === $revista ? null : $revista;
    }

    /**
     * Revistas del catálogo (búsqueda de revistas de Primo VE, pestaña jsearch_slot) por palabras del título y/o por
     * categoría (las del sitio «Revistas» de Primo, p. ej. "Health_Biological_Sciences─Human_Anatomy_Physiology"),
     * ordenadas por título. La categoría va en el parámetro "journals=category,<categoría>", como en la búsqueda de
     * revistas de Primo; sin palabras, la consulta es "any,contains,*".
     *
     * @return array{total: int, revistas: list<array{titulo: string, issn: ?string, eissn: ?string, editorial: ?string, registro: string, mms: ?string}>}|null null si no hay criterios o el catálogo no responde
     */
    public function buscarRevistas(?string $texto, ?string $categoria, int $desde = 0, int $cantidad = 20): ?array
    {
        $this->errorRevistas = null;
        $texto = self::criterio($texto);
        $categoria = self::categoriaRevistas($categoria);
        if (!$this->buscaPorIsbn() || (null === $texto && null === $categoria)) {
            return null;
        }
        $parametros = [
            'q' => null !== $texto ? 'any,contains,'.$texto : 'any,contains,*',
            'offset' => max(0, min($desde, 1980)),
            'limit' => $cantidad,
            'journals' => null !== $categoria ? 'category,'.$categoria : null,
        ];

        $resultado = $this->cache->get('revistas-'.md5(json_encode($parametros)), function (ItemInterface $item) use ($parametros): array {
            $item->expiresAfter(3600);
            $respuesta = $this->consultarRevistas($parametros + ['sort' => 'title']) ?? $this->consultarRevistas($parametros);
            if (null === $respuesta) {
                $item->expiresAfter(0);

                return [];
            }
            $revistas = [];
            foreach ($respuesta['docs'] as $doc) {
                $revista = \is_array($doc) ? self::datosDeRevista($doc) : [];
                if ([] !== $revista) {
                    $revistas[] = $revista;
                }
            }

            return ['total' => $respuesta['total'], 'revistas' => $revistas];
        });

        return [] === $resultado ? null : $resultado;
    }

    /**
     * Categoría de revistas de Primo: el nombre tal cual ("Health_Biological_Sciences─Human_Anatomy_Physiology"), con
     * o sin "category,". Se aceptan el guion largo "─" (U+2500) que separa la categoría de la subcategoría o, por si se
     * escribe a mano, "--" o "—".
     */
    public static function categoriaRevistas(?string $categoria): ?string
    {
        $categoria = trim((string) preg_replace('/^category,/', '', trim((string) $categoria)));
        $categoria = str_replace(['--', '—', '–'], '─', $categoria);

        return 1 === preg_match('/^[\p{L}\p{N}_─&,.\- ]{1,150}$/u', $categoria) ? $categoria : null;
    }

    /**
     * Consulta la búsqueda de revistas de Primo VE (GET /primo/v1/search con tab=jsearch_slot). La dirección se arma
     * a mano para que las comas de "q" y "journals" vayan tal cual, como en la búsqueda de revistas de Primo.
     *
     * @param array<string, string|int|null> $parametros q, offset, limit, journals y sort
     *
     * @return array{total: int, docs: list<mixed>}|null null si Primo no responde o responde con error (queda en errorRevistas())
     */
    private function consultarRevistas(array $parametros): ?array
    {
        $consulta = array_filter([
            'vid' => $this->primoVid,
            'tab' => 'jsearch_slot',
            'scope' => 'MyInst_and_CI',
            'lang' => 'es',
            ...$parametros,
        ], fn ($v) => null !== $v && '' !== $v);
        $url = rtrim($this->apiUrl, '/').'/primo/v1/search?'
            .strtr(http_build_query($consulta, '', '&', \PHP_QUERY_RFC3986), ['%2C' => ',', '%3A' => ':', '%2A' => '*'])
            .'&apikey='.rawurlencode($this->primoClave);
        try {
            $respuesta = $this->http->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::ESPERA,
                'max_duration' => self::ESPERA * 2,
            ]);
            $codigo = $respuesta->getStatusCode();
            $datos = json_decode($respuesta->getContent(false), true);
        } catch (\Throwable $e) {
            $this->errorRevistas = $e::class;
            $this->logger->warning('La búsqueda de revistas de Primo no respondió: {error}', ['error' => $e::class]);

            return null;
        }
        if (200 !== $codigo || !\is_array($datos) || !\is_array($datos['docs'] ?? null)) {
            $mensaje = \is_array($datos) ? ($datos['errorList']['error'][0]['errorMessage'] ?? $datos['errorList']['error']['errorMessage'] ?? null) : null;
            $this->errorRevistas = trim('HTTP '.$codigo.' '.(\is_string($mensaje) ? mb_substr($mensaje, 0, 200) : ''));
            $this->logger->warning('La búsqueda de revistas de Primo respondió con error: {error} (consulta {consulta})', [
                'error' => $this->errorRevistas,
                'consulta' => json_encode(array_diff_key($consulta, ['apikey' => true]), \JSON_UNESCAPED_UNICODE),
            ]);

            return null;
        }

        return ['total' => (int) ($datos['info']['total'] ?? \count($datos['docs'])), 'docs' => $datos['docs']];
    }

    /**
     * @param array<string, mixed> $doc registro de la búsqueda de revistas de Primo
     *
     * @return array{titulo: string, issn: ?string, eissn: ?string, editorial: ?string, registro: string, mms: ?string}|array{}
     */
    private static function datosDeRevista(array $doc): array
    {
        $datos = self::datosDeRegistro($doc);
        if ([] === $datos) {
            return [];
        }
        $issns = [];
        foreach ([...(array) ($doc['pnx']['addata']['issn'] ?? []), ...(array) ($doc['pnx']['addata']['eissn'] ?? [])] as $valor) {
            if (null !== $issn = self::issn((string) $valor)) {
                $issns[$issn] = true;
            }
        }
        $issns = array_keys($issns);

        return [
            'titulo' => $datos['titulo'],
            'issn' => $issns[0] ?? null,
            'eissn' => $issns[1] ?? null,
            'editorial' => $datos['editorial'],
            'registro' => $datos['registro'],
            'mms' => $datos['mms'],
        ];
    }

    /** ISSN con guion ("1873-2380") si el texto tiene la forma de un ISSN; null si no. */
    public static function issn(?string $texto): ?string
    {
        $issn = strtoupper((string) preg_replace('/[^0-9Xx]/', '', (string) $texto));

        return 1 === preg_match('/^\d{7}[\dX]$/', $issn) ? substr($issn, 0, 4).'-'.substr($issn, 4) : null;
    }

    /** Valor de búsqueda sin comas ni punto y coma (separan los criterios en la consulta de Primo). */
    private static function criterio(?string $texto): ?string
    {
        $texto = trim((string) preg_replace('/[,;]+|\s+/u', ' ', (string) $texto));

        return '' === $texto ? null : mb_substr($texto, 0, 200);
    }

    private static function comparable(string $texto): string
    {
        return mb_strtolower((string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($texto, \Normalizer::FORM_D)));
    }

    /** Primer ISBN válido del registro (prefiere el de 13 dígitos). */
    private static function isbnDeRegistro(array $doc): ?string
    {
        $candidatos = [];
        foreach ((array) ($doc['pnx']['addata']['isbn'] ?? []) as $valor) {
            $isbn = strtoupper((string) preg_replace('/[^0-9Xx]/', '', explode(' ', trim((string) $valor))[0]));
            if (1 === preg_match('/^(\d{13}|\d{9}[\dX])$/', $isbn)) {
                $candidatos[] = $isbn;
            }
        }
        usort($candidatos, fn (string $a, string $b) => \strlen($b) <=> \strlen($a));

        return $candidatos[0] ?? null;
    }

    /** Búsqueda de revistas de Primo VE (de ahí se copia el enlace de una categoría para el bloque Revistas). */
    #[AsTwigFunction('catalogo_url_revistas')]
    public function urlRevistas(string $idioma): ?string
    {
        if ('' === $this->primoUrl || '' === $this->primoVid) {
            return null;
        }

        return rtrim($this->primoUrl, '/').'/discovery/jsearch?'.strtr(http_build_query([
            'vid' => $this->primoVid,
            'lang' => $idioma,
        ], '', '&', \PHP_QUERY_RFC3986), ['%3A' => ':']);
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
            // Vista completa: la breve no trae el MARC ("anies") donde Alma agrega los campos AVA de disponibilidad.
            $respuesta = $this->consultar('/almaws/v1/bibs', [
                'mms_id' => implode(',', \array_slice($mms, 0, 100)),
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

        $editorial = $valor('display.publisher', 'addata.pub');

        return [
            'titulo' => self::sinPuntuacionIsbd($titulo),
            'autor' => self::autorSinFuncion($valor('display.creator', 'display.contributor', 'addata.au')),
            // "Buenos Aires : Alfagrama Ediciones" → "Alfagrama Ediciones" (el lugar no se muestra en la guía).
            'editorial' => null === $editorial ? null : self::sinPuntuacionIsbd(trim((string) preg_replace('/^.*\s:\s*/u', '', $editorial), ' ,;')),
            'anio' => $anio[0] ?? null,
            'registro' => $registro,
            'mms' => null !== $mms && preg_match('/^\d{6,25}$/', $mms) ? $mms : null,
        ];
    }

    /** Quita la puntuación de catalogación: "Título : subtítulo /" → "Título: subtítulo"; punto final (salvo iniciales). */
    public static function sinPuntuacionIsbd(string $texto): string
    {
        $texto = (string) preg_replace('/\s+([:;,])/u', '$1', trim($texto, " \t\n/=;:,"));

        return (string) preg_replace('/(?<!\b\p{Lu})\.$/u', '', $texto);
    }

    /** "Parada, Alejandro E., author." → "Parada, Alejandro E."; "Cormen, Thomas H., coaut." → "Cormen, Thomas H." */
    public static function autorSinFuncion(?string $autor): ?string
    {
        if (null === $autor) {
            return null;
        }
        $autor = (string) preg_replace('/,\s*(author|autora?|coaut|coautora?|editora?|editor literario|ed|eds|compiladora?|comp|translator|traductora?|trad|illustrator|ilustradora?|director|directora)\.?$/iu', '', trim($autor));

        return self::sinPuntuacionIsbd($autor);
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
