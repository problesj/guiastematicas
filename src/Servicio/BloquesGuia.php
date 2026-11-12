<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Recurso;
use App\Entity\Usuario;
use App\Repository\MateriaRepository;
use App\Repository\RecursoRepository;
use App\Repository\UsuarioRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;

/** Datos que necesitan los bloques de una guía al mostrarse (recursos, perfil, búsqueda en Primo VE). */
final class BloquesGuia
{
    /** Tipos de material del bloque de búsqueda y su valor en la faceta «rtype» de Primo VE. */
    public const TIPOS_MATERIAL = ['libros' => 'books', 'tesis' => 'dissertations', 'articulos' => 'articles', 'revistas' => 'journals'];

    public function __construct(
        private readonly RecursoRepository $recursos,
        private readonly UsuarioRepository $usuarios,
        private readonly MateriaRepository $materias,
        #[Autowire('%env(string:default::PRIMO_URL)%')]
        private readonly string $primoUrl,
        #[Autowire('%env(string:default::PRIMO_VID)%')]
        private readonly string $primoVid,
    ) {
    }

    /**
     * Recursos del bloque en el orden elegido. En la vista pública se omiten los ocultos o con la prueba vencida.
     *
     * @return list<Recurso>
     */
    #[AsTwigFunction('recursos_del_bloque')]
    public function recursos(Bloque $bloque, bool $soloDisponibles = true): array
    {
        $ids = array_values(array_filter((array) $bloque->dato('ids', []), 'is_int'));
        if ([] === $ids) {
            return [];
        }
        $porId = [];
        foreach ($this->recursos->findBy(['id' => $ids]) as $recurso) {
            $porId[$recurso->getId()] = $recurso;
        }
        $lista = [];
        foreach ($ids as $id) {
            $recurso = $porId[$id] ?? null;
            if (null !== $recurso && (!$soloDisponibles || $recurso->estaDisponible())) {
                $lista[] = $recurso;
            }
        }

        return $lista;
    }

    #[AsTwigFunction('perfil_del_bloque')]
    public function perfil(Bloque $bloque): ?Usuario
    {
        $id = $bloque->dato('usuario');
        $usuario = \is_int($id) ? $this->usuarios->find($id) : null;

        return null !== $usuario && $usuario->isActivo() ? $usuario : null;
    }

    /** Si el bloque de búsqueda puede funcionar (PRIMO_URL y PRIMO_VID definidos). */
    #[AsTwigFunction('hay_buscador_primo')]
    public function hayBuscadorPrimo(): bool
    {
        return '' !== $this->primoUrl && '' !== $this->primoVid;
    }

    /**
     * Materias marcadas por defecto en el bloque de búsqueda: las elegidas al editarlo o, si nunca se eligieron, las de
     * la guía. Solo las activas.
     *
     * @return list<Materia>
     */
    #[AsTwigFunction('materias_busqueda')]
    public function materiasBusqueda(Bloque $bloque, ?Guia $guia = null): array
    {
        $ids = $bloque->dato('materias');
        $lista = \is_array($ids)
            ? ([] === $ids ? [] : $this->materias->findBy(['id' => array_map('intval', $ids)]))
            : (($guia ?? $bloque->getGuia())?->getMaterias()->toArray() ?? []);
        $lista = array_values(array_filter($lista, fn (Materia $m) => $m->isActiva()));
        usort($lista, fn (Materia $a, Materia $b) => strcoll($a->getNombre(), $b->getNombre()));

        return $lista;
    }

    /**
     * Materias activas que no están en la lista dada (para «Otras materias»).
     *
     * @param list<Materia> $excepto
     *
     * @return list<Materia>
     */
    #[AsTwigFunction('otras_materias_busqueda')]
    public function otrasMaterias(array $excepto): array
    {
        return array_values(array_filter($this->materias->activas(), fn (Materia $m) => !\in_array($m, $excepto, true)));
    }

    /**
     * Dirección de resultados de Primo VE, p. ej. .../discovery/search?query=any,contains,agua&vid=...
     *
     * Con materias, la búsqueda pasa a modo avanzado y agrega una línea por tema (sub) con las materias unidas por OR;
     * el tipo de material se aplica como faceta (rtype).
     *
     * @param list<string> $materias nombres de materias
     */
    public function enlacePrimo(string $texto, string $idioma, array $materias = [], ?string $tipo = null): ?string
    {
        $limpiar = fn (string $v): string => trim((string) preg_replace('/\s+/', ' ', str_replace([',', '"'], ' ', $v)));
        $texto = $limpiar($texto);
        $materias = array_values(array_filter(array_map($limpiar, $materias)));
        if (!$this->hayBuscadorPrimo() || ('' === $texto && [] === $materias)) {
            return null;
        }

        $consultas = [];
        if ('' !== $texto) {
            $consultas[] = 'any,contains,'.$texto.([] === $materias ? '' : ',AND');
        }
        if ([] !== $materias) {
            $consultas[] = 'sub,contains,'.implode(' OR ', array_map(fn ($m) => '"'.$m.'"', $materias)).',AND';
        }
        $parametros = array_map(fn ($c) => 'query='.self::codificar($c), $consultas);
        foreach ([
            'tab' => 'Everything',
            'search_scope' => 'MyInst_and_CI',
            'vid' => $this->primoVid,
            'lang' => $idioma,
            'offset' => '0',
        ] + ([] === $materias ? [] : ['mode' => 'advanced'])
          + (isset(self::TIPOS_MATERIAL[$tipo ?? '']) ? ['facet' => 'rtype,include,'.self::TIPOS_MATERIAL[$tipo]] : []) as $clave => $valor) {
            $parametros[] = $clave.'='.self::codificar($valor);
        }

        return rtrim($this->primoUrl, '/').'/discovery/search?'.implode('&', $parametros);
    }

    /** RFC 3986 (espacios como %20) y comas y dos puntos legibles, como en las direcciones del propio Primo. */
    private static function codificar(string $valor): string
    {
        return strtr(rawurlencode($valor), ['%2C' => ',', '%3A' => ':']);
    }
}
