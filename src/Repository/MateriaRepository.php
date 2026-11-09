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

    /** @return list<Materia> */
    public function todas(): array
    {
        return $this->findBy([], ['activa' => 'DESC', 'nombre' => 'ASC']);
    }
}
