<?php

declare(strict_types=1);

namespace App\Paginacion;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Una página de resultados. Lee "pagina" y "por_pagina" de la petición; una página fuera de rango muestra la última.
 *
 * @template T
 */
final class Pagina
{
    /** Cantidades que se pueden elegir en el selector "por página". */
    public const array OPCIONES = [10, 25, 50, 100];
    public const int POR_PAGINA = 25;

    /**
     * @param list<T> $elementos
     */
    private function __construct(
        public readonly array $elementos,
        public readonly int $total,
        public readonly int $numero,
        public readonly int $porPagina,
    ) {
    }

    /** @return array{0: int, 1: int} [página pedida, cantidad por página] */
    public static function leerParametros(Request $request): array
    {
        $porPagina = $request->query->getInt('por_pagina', self::POR_PAGINA);

        return [
            max(1, $request->query->getInt('pagina', 1)),
            \in_array($porPagina, self::OPCIONES, true) ? $porPagina : self::POR_PAGINA,
        ];
    }

    /**
     * @param bool $uneColecciones true si la consulta trae colecciones con JOIN (cuenta y corta por registro principal)
     *
     * @return self<mixed>
     */
    public static function desdeConsulta(QueryBuilder $consulta, int $numero, int $porPagina, bool $uneColecciones = false): self
    {
        $paginador = new Paginator($consulta, $uneColecciones);
        $total = \count($paginador);
        $numero = min(max(1, $numero), max(1, (int) ceil($total / $porPagina)));
        $paginador->getQuery()->setFirstResult(($numero - 1) * $porPagina)->setMaxResults($porPagina);

        return new self(iterator_to_array($paginador, false), $total, $numero, $porPagina);
    }

    public function getPaginas(): int
    {
        return max(1, (int) ceil($this->total / $this->porPagina));
    }

    public function getDesde(): int
    {
        return 0 === $this->total ? 0 : ($this->numero - 1) * $this->porPagina + 1;
    }

    public function getHasta(): int
    {
        return min($this->total, $this->numero * $this->porPagina);
    }

    /**
     * Números de página a mostrar, con null donde se omiten ("1 … 4 5 6 … 20").
     *
     * @return list<int|null>
     */
    public function getEnlaces(int $alrededor = 2): array
    {
        $enlaces = [];
        $anterior = 0;
        for ($n = 1; $n <= $this->getPaginas(); ++$n) {
            if (1 === $n || $n === $this->getPaginas() || abs($n - $this->numero) <= $alrededor) {
                if ($n - $anterior > 1) {
                    $enlaces[] = null;
                }
                $enlaces[] = $n;
                $anterior = $n;
            }
        }

        return $enlaces;
    }
}
