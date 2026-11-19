<?php

declare(strict_types=1);

namespace App\Repository;

use App\Clasificacion\Asjc;
use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Recurso;
use App\Entity\Usuario;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Materia>
 */
class MateriaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Materia::class);
    }

    /** @return list<Materia> */
    public function activas(): array
    {
        return $this->findBy(['activa' => true], ['nombre' => 'ASC']);
    }

    /**
     * Materias activas ordenadas como en los selectores: por campo ASJC, cada categoría seguida de las materias propias
     * clasificadas en ella; las propias sin clasificar, al final.
     *
     * @return list<Materia>
     */
    public function porCampo(): array
    {
        return self::ordenarPorCampo($this->activas());
    }

    /**
     * @param list<Materia> $materias
     *
     * @return list<Materia>
     */
    public static function ordenarPorCampo(array $materias): array
    {
        $clave = function (Materia $m): array {
            $codigos = $m->getAsjc();

            return [[] === $codigos ? 1 : 0, $m->getCampoAsjc(), $codigos[0] ?? 0, $m->isBase() ? 0 : 1, mb_strtolower($m->getNombre())];
        };
        usort($materias, fn (Materia $a, Materia $b) => $clave($a) <=> $clave($b));

        return $materias;
    }

    /**
     * Materias activas con contenido: usadas en recursos, guías o perfiles, y todas las propias. Son las que se ofrecen
     * en los filtros públicos (las 334 categorías ASJC completas solo aparecen al asignar materias).
     *
     * @return list<Materia>
     */
    public function enUso(): array
    {
        $usadas = [];
        foreach ([Recurso::class, Guia::class, Usuario::class] as $clase) {
            $ids = $this->getEntityManager()->createQueryBuilder()
                ->select('DISTINCT m.id')->from($clase, 'x')->join('x.materias', 'm')
                ->getQuery()->getSingleColumnResult();
            foreach ($ids as $id) {
                $usadas[(int) $id] = true;
            }
        }

        return array_values(array_filter($this->activas(), fn (Materia $m) => !$m->isBase() || isset($usadas[$m->getId()])));
    }

    /**
     * La materia y, si es una categoría ASJC, las materias propias clasificadas en ella (para filtrar).
     *
     * @return list<Materia>
     */
    public function conClasificadas(Materia $materia): array
    {
        if (!$materia->isBase()) {
            return [$materia];
        }
        $propias = array_filter(
            $this->findBy(['codigoAsjc' => null]),
            fn (Materia $m) => \in_array($materia->getCodigoAsjc(), $m->getAsjc(), true),
        );

        return [$materia, ...array_values($propias)];
    }

    /**
     * Las materias dadas más sus parientes en el esquema ASJC: las propias clasificadas en cada categoría y las
     * categorías en que está clasificada cada propia (para reunir recursos de una guía).
     *
     * @param list<Materia> $materias
     *
     * @return list<Materia>
     */
    public function relacionadas(array $materias): array
    {
        $resultado = [];
        foreach ($materias as $materia) {
            foreach ($this->conClasificadas($materia) as $m) {
                $resultado[$m->getId() ?? spl_object_id($m)] = $m;
            }
            if (!$materia->isBase() && [] !== $materia->getAsjc()) {
                foreach ($this->findBy(['codigoAsjc' => $materia->getAsjc()]) as $m) {
                    $resultado[$m->getId()] = $m;
                }
            }
        }

        return array_values($resultado);
    }

    /**
     * Materias propias activas aún sin categoría ASJC (anteriores al esquema ASJC): el panel avisa para clasificarlas.
     *
     * @return list<Materia>
     */
    public function sinClasificar(): array
    {
        return array_values(array_filter($this->findBy(['codigoAsjc' => null, 'activa' => true]), fn (Materia $m) => [] === $m->getAsjc()));
    }

    /**
     * Materias activas de la gran área (base o propias clasificadas en ella).
     *
     * @return list<Materia>
     */
    public function deArea(string $area): array
    {
        return array_values(array_filter($this->activas(), fn (Materia $m) => \in_array($area, $m->getAreas(), true)));
    }

    /**
     * Grandes áreas ASJC que tienen al menos una materia con contenido, en el orden de Asjc::AREAS.
     *
     * @return list<string>
     */
    public function areasConMaterias(): array
    {
        $areas = array_merge([], ...array_map(fn (Materia $m) => $m->getAreas(), $this->enUso()));

        return array_values(array_filter(Asjc::AREAS, fn (string $a) => \in_array($a, $areas, true)));
    }

    /** @return list<Materia> */
    public function todas(): array
    {
        return $this->findBy([], ['activa' => 'DESC', 'nombre' => 'ASC']);
    }
}
