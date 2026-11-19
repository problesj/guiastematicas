<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Materia;
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
     * Materias activas asociadas a alguna categoría ASJC de la gran área.
     *
     * @return list<Materia>
     */
    public function deArea(string $area): array
    {
        return array_values(array_filter($this->activas(), fn (Materia $m) => \in_array($area, $m->getAreas(), true)));
    }

    /**
     * Grandes áreas ASJC que tienen al menos una materia activa, en el orden de Asjc::AREAS.
     *
     * @return list<string>
     */
    public function areasConMaterias(): array
    {
        $areas = array_merge([], ...array_map(fn (Materia $m) => $m->getAreas(), $this->activas()));

        return array_values(array_filter(\App\Clasificacion\Asjc::AREAS, fn (string $a) => \in_array($a, $areas, true)));
    }

    /** @return list<Materia> */
    public function todas(): array
    {
        return $this->findBy([], ['activa' => 'DESC', 'nombre' => 'ASC']);
    }
}
