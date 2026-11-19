<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Configuracion\Ajustes;
use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Pestana;
use App\Entity\Recurso;
use App\Entity\Seccion;
use App\Entity\Usuario;
use App\Enum\TipoBloque;
use App\Enum\TipoRecurso;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Arma una guía completa a partir de sus materias y de las carreras elegidas en Biblioges:
 *
 * 1. Presentación: texto de bienvenida y perfil del responsable y de cada coeditor.
 * 2. Bases de datos de las materias y, vinculado, el bloque transversal de bases multidisciplinarias.
 * 3. Revistas de las materias.
 * 4. Sitios web recomendados de las materias y, aparte, los que sugiera Gemini (solo los que responden; quedan
 *    marcados para revisar y no entran a la lista A-Z).
 * 5. Normas de citación y gestores bibliográficos: la pestaña transversal, vinculada.
 * 6. Libros de las materias (lista A-Z) y bibliografía básica disponible de las carreras (Biblioges, última edición).
 *
 * Todos los recursos salen de la lista A-Z o de Biblioges: con Gemini se redactan los textos y se eligen y ordenan los
 * recursos más pertinentes (solo entre los candidatos). Sin Gemini, o si falla, se usan todos los candidatos en orden
 * alfabético y un texto de presentación estándar. La guía queda en borrador para revisarla.
 */
final class AsistenteGuias
{
    /** Pestañas de recursos: clave en la respuesta de Gemini => tipo de recurso. */
    public const array CATEGORIAS = [
        'bases_datos' => TipoRecurso::BaseDatos,
        'revistas' => TipoRecurso::Revistas,
        'sitios_web' => TipoRecurso::SitioWeb,
        'libros' => TipoRecurso::Libros,
    ];
    private const int MAXIMO_CANDIDATOS = 80; // por categoría, para el texto que se envía a Gemini
    private const int MAXIMO_SIN_IA = 40;     // recursos por bloque cuando no hay selección de Gemini
    private const int MAXIMO_LIBROS_BIBLIOGES = 60;
    private const int MAXIMO_SUGERIDOS = 8;

    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Gemini $gemini,
        private readonly Biblioges $biblioges,
        private readonly LibrosEnRecursos $librosEnRecursos,
        private readonly ContenidoCompartido $compartido,
        private readonly Ajustes $ajustes,
        private readonly TranslatorInterface $traductor,
        HttpClientInterface $http,
        #[Autowire('%kernel.environment%')]
        string $entorno = 'prod',
    ) {
        // Las direcciones las propone la IA: nunca se consulta la red interna del servidor (en las pruebas las
        // respuestas son simuladas y los dominios de ejemplo no se resuelven).
        $this->http = 'test' === $entorno ? $http : new NoPrivateNetworkHttpClient($http);
    }

    public function iaDisponible(): bool
    {
        return $this->gemini->disponible();
    }

    public function bloqueMultidisciplinarias(): ?Bloque
    {
        $id = (int) $this->ajustes->valor('IA_BLOQUE_MULTIDISCIPLINARIAS');
        $bloque = $id > 0 ? $this->em->find(Bloque::class, $id) : null;

        return null !== $bloque && $bloque->isReutilizable() ? $bloque : null;
    }

    public function pestanaCitacion(): ?Pestana
    {
        $id = (int) $this->ajustes->valor('IA_PESTANA_CITACION');
        $pestana = $id > 0 ? $this->em->find(Pestana::class, $id) : null;

        return null !== $pestana && $pestana->isReutilizable() ? $pestana : null;
    }

    /**
     * Agrega a la guía (ya guardada, sin pestañas, con sus materias y coeditores) las pestañas y bloques.
     *
     * @param list<string> $carreras códigos de carrera en Biblioges
     *
     * @return list<string> avisos para el editor (claves de traducción del dominio messages)
     */
    public function armar(Guia $guia, array $carreras, bool $usarIa): array
    {
        $avisos = [];
        $idioma = $guia->getIdioma();
        $t = fn (string $clave, array $parametros = []) => $this->traductor->trans('asistente.'.$clave, $parametros, null, $idioma);
        $materias = $guia->getMaterias()->toArray();
        $nombresMaterias = implode(', ', array_map(fn (Materia $m) => $m->getNombre(), $materias));

        $candidatos = [];
        foreach (self::CATEGORIAS as $clave => $tipo) {
            $candidatos[$clave] = $this->candidatos($materias, $tipo);
        }
        $librosBiblioges = $this->librosBiblioges($carreras, $avisos);

        $ia = null;
        if ($usarIa) {
            $ia = $this->consultarIa($guia, $nombresMaterias, $candidatos, $librosBiblioges);
            if (null === $ia) {
                $avisos[] = 'asistente.aviso_ia_fallo';
            }
        }

        // 1. Presentación
        $presentacion = $this->nuevaPestana($guia, $t('pestana_presentacion'));
        $textoPresentacion = $ia['presentacion'] ?? $t('texto_presentacion', ['%guia%' => $guia->getTitulo(), '%materias%' => $nombresMaterias]);
        $presentacion->agregarSeccion((new Seccion($t('seccion_bienvenida')))->agregarBloque(self::texto($textoPresentacion)));
        $personas = [];
        foreach ([$guia->getResponsable(), ...$guia->getCoeditores()->toArray()] as $persona) {
            if ($persona instanceof Usuario) {
                $personas[spl_object_id($persona)] = $persona;
            }
        }
        $personas = array_values($personas);
        if ([] !== $personas) {
            $contacto = (new Seccion($t('seccion_contacto')))->setColumnas(min(3, \count($personas)));
            foreach ($personas as $persona) {
                $contacto->agregarBloque((new Bloque(TipoBloque::Perfil))->setContenido(['usuario' => $persona->getId()]));
            }
            $presentacion->agregarSeccion($contacto);
        }

        // 2 a 4. Bases de datos, revistas y sitios web
        foreach (['bases_datos', 'revistas', 'sitios_web'] as $clave) {
            $pestana = $this->nuevaPestana($guia, $t('pestana_'.$clave));
            $seccion = new Seccion($t('seccion_'.$clave, ['%materias%' => $nombresMaterias]));
            $this->agregarIntroduccion($seccion, $ia, $clave);
            $bloque = $this->bloqueRecursos($candidatos[$clave], $ia['seleccion'][$clave] ?? null, $t('bloque_'.$clave));
            if (null !== $bloque) {
                $seccion->agregarBloque($bloque);
            } else {
                $seccion->agregarBloque(self::texto($t('sin_recursos')));
                $avisos[] = 'asistente.aviso_sin_'.$clave;
            }
            $pestana->agregarSeccion($seccion);
            if ('sitios_web' === $clave && [] !== ($ia['sugeridos'] ?? [])) {
                $sugeridos = $this->sitiosQueResponden($ia['sugeridos'], $candidatos['sitios_web']);
                if ([] !== $sugeridos) {
                    $pestana->agregarSeccion((new Seccion($t('seccion_sugeridos')))->agregarBloque(
                        (new Bloque(TipoBloque::Enlaces))->setTitulo($t('bloque_sugeridos'))->setContenido(['items' => $sugeridos])
                    ));
                    $avisos[] = 'asistente.aviso_sugeridos';
                }
            }
            if ('bases_datos' === $clave) {
                $multidisciplinarias = $this->bloqueMultidisciplinarias();
                if (null !== $multidisciplinarias && $multidisciplinarias->getGuia() !== $guia) {
                    $seccionMulti = new Seccion($t('seccion_multidisciplinarias'));
                    $pestana->agregarSeccion($seccionMulti);
                    $this->compartido->reutilizarBloque($multidisciplinarias, $seccionMulti, true);
                } else {
                    $avisos[] = 'asistente.aviso_sin_multidisciplinarias';
                }
            }
        }

        // 5. Normas de citación y gestores bibliográficos (transversal)
        $citacion = $this->pestanaCitacion();
        if (null !== $citacion && $citacion->getGuia() !== $guia) {
            $this->compartido->reutilizarPestana($citacion, $guia, true)->setTitulo($t('pestana_citacion'));
        } else {
            $avisos[] = 'asistente.aviso_sin_citacion';
        }

        // 6. Libros
        $libros = $this->nuevaPestana($guia, $t('pestana_libros'));
        $seccion = new Seccion($t('seccion_libros', ['%materias%' => $nombresMaterias]));
        $this->agregarIntroduccion($seccion, $ia, 'libros');
        $bloque = $this->bloqueRecursos($candidatos['libros'], $ia['seleccion']['libros'] ?? null, $t('bloque_libros'));
        if (null !== $bloque) {
            $seccion->agregarBloque($bloque);
        }
        if ([] !== $librosBiblioges) {
            $items = $this->librosEnRecursos->incorporar($librosBiblioges, array_fill(0, \count($librosBiblioges), null), $guia);
            $comparador = class_exists(\Collator::class) ? new \Collator('es') : null;
            usort($items, fn (array $a, array $b): int => null !== $comparador ? $comparador->compare((string) $a['titulo'], (string) $b['titulo']) : strnatcasecmp((string) $a['titulo'], (string) $b['titulo']));
            $seccion->agregarBloque((new Bloque(TipoBloque::Libros))->setTitulo($t('bloque_bibliografia'))->setContenido([
                'orden' => 'titulo', 'presentacion' => 'lista', 'columnas' => 0, 'por_pagina' => 10, 'items' => $items,
            ]));
        }
        if ($seccion->getBloques()->isEmpty()) {
            $seccion->agregarBloque(self::texto($t('sin_recursos')));
            $avisos[] = 'asistente.aviso_sin_libros';
        }
        $libros->agregarSeccion($seccion);

        return array_values(array_unique($avisos));
    }

    /**
     * Recursos disponibles de las materias y del tipo dado, por título.
     *
     * @param list<Materia> $materias
     *
     * @return list<Recurso>
     */
    public function candidatos(array $materias, TipoRecurso $tipo): array
    {
        if ([] === $materias) {
            return [];
        }
        $recursos = $this->em->createQueryBuilder()->select('DISTINCT r')->from(Recurso::class, 'r')->join('r.materias', 'm')
            ->where('m IN (:materias)')->andWhere('r.tipo = :tipo')
            ->setParameter('materias', $this->em->getRepository(Materia::class)->relacionadas($materias))->setParameter('tipo', $tipo)
            ->orderBy('r.titulo')->getQuery()->getResult();

        return \array_slice(array_values(array_filter($recursos, fn (Recurso $r) => $r->estaDisponible())), 0, self::MAXIMO_CANDIDATOS);
    }

    private function nuevaPestana(Guia $guia, string $titulo): Pestana
    {
        $pestana = new Pestana($titulo);
        $guia->agregarPestana($pestana);

        return $pestana;
    }

    /** @param array<string, mixed>|null $ia */
    private function agregarIntroduccion(Seccion $seccion, ?array $ia, string $clave): void
    {
        $texto = $ia['introducciones'][$clave] ?? null;
        if (\is_string($texto) && '' !== trim($texto)) {
            $seccion->agregarBloque(self::texto($texto));
        }
    }

    /**
     * Bloque de recursos: los elegidos por Gemini (en su orden) o, sin selección, los candidatos en orden alfabético.
     *
     * @param list<Recurso> $candidatos
     */
    private function bloqueRecursos(array $candidatos, mixed $seleccion, string $titulo): ?Bloque
    {
        $porId = [];
        foreach ($candidatos as $recurso) {
            $porId[$recurso->getId()] = $recurso;
        }
        $ids = [];
        foreach (\is_array($seleccion) ? $seleccion : [] as $id) {
            if (\is_int($id) && isset($porId[$id])) {
                $ids[$id] = $id;
            }
        }
        $elegidos = [] !== $ids;
        $ids = $elegidos ? array_values($ids) : \array_slice(array_keys($porId), 0, self::MAXIMO_SIN_IA);
        if ([] === $ids) {
            return null;
        }

        return (new Bloque(TipoBloque::Recursos))->setTitulo($titulo)
            ->setContenido(['ids' => $ids, 'descripcion' => true, 'orden' => $elegidos ? 'manual' : 'titulo']);
    }

    /**
     * Bibliografía básica disponible en la biblioteca (última edición) de las carreras, sin repetir, en el formato de
     * los libros del bloque.
     *
     * @param list<string> $carreras
     * @param list<string> $avisos
     *
     * @return list<array<string, mixed>>
     */
    private function librosBiblioges(array $carreras, array &$avisos): array
    {
        if ([] === $carreras) {
            return [];
        }
        if (!$this->biblioges->disponible()) {
            $avisos[] = 'asistente.aviso_biblioges';

            return [];
        }
        $libros = [];
        foreach ($carreras as $codigo) {
            $resultado = $this->biblioges->bibliografias('carrera', $codigo, 'basica', 'disponibles', true);
            if (isset($resultado['error'])) {
                $avisos[] = 'asistente.aviso_biblioges';
                continue;
            }
            foreach ($resultado['grupos'] as $grupo) {
                foreach ($grupo['libros'] as $libro) {
                    $clave = $libro['mms'] ?? $libro['isbn'] ?? mb_strtolower((string) $libro['titulo']);
                    $libros[$clave] ??= [
                        'recurso' => null,
                        'titulo' => $libro['titulo'],
                        'autor' => $libro['autor'],
                        'isbn' => $libro['isbn'],
                        'doi' => $libro['doi'],
                        'editorial' => $libro['editorial'],
                        'anio' => $libro['anio'],
                        'enlace' => $libro['enlace'],
                        'formato' => null,
                        'registro' => null,
                        'mms' => $libro['mms'],
                    ];
                }
            }
        }

        return \array_slice(array_values($libros), 0, self::MAXIMO_LIBROS_BIBLIOGES);
    }

    /**
     * Pide a Gemini los textos y la selección de recursos. Solo se envían nombres de materias y carreras y títulos y
     * descripciones de recursos (nada de datos personales).
     *
     * @param array<string, list<Recurso>>   $candidatos
     * @param list<array<string, mixed>>      $librosBiblioges
     *
     * @return array{presentacion: string, introducciones: array<string, string>, seleccion: array<string, list<int>>, sugeridos: list<array{titulo: string, url: string, descripcion: ?string}>}|null
     */
    private function consultarIa(Guia $guia, string $materias, array $candidatos, array $librosBiblioges): ?array
    {
        $datos = [
            'guia' => $guia->getTitulo(),
            'descripcion' => $guia->getDescripcion(),
            'idioma' => 'en' === $guia->getIdioma() ? 'inglés' : 'español',
            'materias' => $materias,
            'bibliografia_basica_de_carreras' => array_map(fn (array $l) => $l['titulo'], \array_slice($librosBiblioges, 0, 30)),
            'candidatos' => array_map(fn (array $lista) => array_map(fn (Recurso $r) => array_filter([
                'id' => $r->getId(),
                'titulo' => $r->getTitulo(),
                'descripcion' => null !== $r->getDescripcion() ? mb_substr($r->getDescripcion(), 0, 200) : null,
            ]), $lista), $candidatos),
        ];
        $sistema = 'Eres bibliotecario/a de Bibliotecas UCN (Universidad Católica del Norte, Chile) y armas guías temáticas para estudiantes. '
            .'Escribe en el idioma indicado, en tono cercano y claro, sin inventar datos. Nunca propongas recursos, enlaces ni títulos que no estén '
            .'en la lista de candidatos: solo eliges entre ellos por su id.';
        $instrucciones = 'Con estos datos de una guía temática (JSON):'."\n".json_encode($datos, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n\n"
            .'Responde solo con un objeto JSON con esta forma: {"presentacion": "dos párrafos breves de bienvenida que expliquen qué encontrará el '
            .'estudiante en la guía, separados por una línea en blanco", "introducciones": {"bases_datos": "...", "revistas": "...", "sitios_web": "...", '
            .'"libros": "..."} (una o dos oraciones por pestaña sobre cómo aprovechar esos recursos en estas materias), "seleccion": {"bases_datos": [ids], '
            .'"revistas": [ids], "sitios_web": [ids], "libros": [ids]} (hasta 25 ids por categoría, del más al menos pertinente para las materias; '
            .'deja fuera los poco relacionados; si una categoría no tiene candidatos, lista vacía), "sitios_sugeridos": [{"titulo": "...", '
            .'"url": "https://...", "descripcion": "una oración"}] (hasta 8 sitios web públicos y confiables —instituciones académicas, '
            .'organismos públicos, sociedades científicas, repositorios— útiles para estas materias que no estén entre los candidatos; '
            .'incluye solo direcciones que existan con certeza, en https; si no conoces ninguno, lista vacía)}. Sin HTML ni Markdown.';

        $respuesta = $this->gemini->json($instrucciones, $sistema);
        if (null === $respuesta || !\is_string($respuesta['presentacion'] ?? null) || '' === trim($respuesta['presentacion'])) {
            return null;
        }
        $limpio = fn (mixed $texto): string => \is_string($texto) ? mb_substr(trim($texto), 0, 3000) : '';
        $resultado = ['presentacion' => $limpio($respuesta['presentacion']), 'introducciones' => [], 'seleccion' => [], 'sugeridos' => []];
        foreach (\array_slice(array_values(array_filter((array) ($respuesta['sitios_sugeridos'] ?? []), 'is_array')), 0, self::MAXIMO_SUGERIDOS) as $sitio) {
            $url = $limpio($sitio['url'] ?? null);
            $titulo = mb_substr($limpio($sitio['titulo'] ?? null), 0, 200);
            if ('' !== $titulo && mb_strlen($url) <= 500 && 1 === preg_match('~^https://[a-z0-9.-]+\.[a-z]{2,}(?:[/?#]\S*)?$~i', $url)) {
                $resultado['sugeridos'][] = ['titulo' => $titulo, 'url' => $url, 'descripcion' => mb_substr($limpio($sitio['descripcion'] ?? null), 0, 500) ?: null];
            }
        }
        foreach (array_keys(self::CATEGORIAS) as $clave) {
            $resultado['introducciones'][$clave] = $limpio($respuesta['introducciones'][$clave] ?? null);
            $resultado['seleccion'][$clave] = array_values(array_filter((array) ($respuesta['seleccion'][$clave] ?? []), 'is_int'));
        }

        return $resultado;
    }

    /**
     * Sitios sugeridos por la IA que responden (código 2xx o 3xx) y no están ya entre los recursos de la guía.
     *
     * @param list<array{titulo: string, url: string, descripcion: ?string}> $sitios
     * @param list<Recurso>                                                  $recursos
     *
     * @return list<array{titulo: string, url: string, descripcion: ?string}>
     */
    private function sitiosQueResponden(array $sitios, array $recursos): array
    {
        $normalizar = fn (string $url) => rtrim(mb_strtolower((string) preg_replace('~^https?://(www\.)?~i', '', $url)), '/');
        $conocidos = array_flip(array_map(fn (Recurso $r) => $normalizar($r->getUrl()), $recursos));
        $respuestas = [];
        foreach ($sitios as $n => $sitio) {
            $clave = $normalizar($sitio['url']);
            if (isset($conocidos[$clave])) {
                continue;
            }
            $conocidos[$clave] = true;
            try {
                $respuestas[$n] = $this->http->request('GET', $sitio['url'], ['timeout' => 8, 'max_duration' => 10, 'max_redirects' => 5, 'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; GuiasTematicas/1.0)']]);
            } catch (\Throwable) { // dirección bloqueada (red interna) o no válida
            }
        }
        $validos = [];
        foreach ($respuestas as $n => $respuesta) {
            try {
                $estado = $respuesta->getStatusCode();
                $respuesta->cancel();
            } catch (\Throwable) {
                continue;
            }
            if ($estado >= 200 && $estado < 400) {
                $validos[] = $sitios[$n];
            }
        }

        return $validos;
    }

    /** Bloque de texto con párrafos de texto plano (se escapan: nada de HTML de la IA). */
    private static function texto(string $texto): Bloque
    {
        $parrafos = array_filter(array_map('trim', preg_split('/\R{2,}/', trim($texto)) ?: []));
        $html = implode('', array_map(fn (string $p) => '<p>'.nl2br(htmlspecialchars($p, \ENT_QUOTES | \ENT_HTML5)).'</p>', $parrafos));

        return (new Bloque(TipoBloque::Texto))->setContenido(['html' => $html]);
    }
}
