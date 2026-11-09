<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Editorial;
use App\Entity\Recurso;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Editorial>
 */
class EditorialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Editorial::class);
    }

    /**
     * Editoriales con la cantidad de recursos de cada una.
     *
     * @return list<array{editorial: Editorial, recursos: int}>
     */
    public function listado(?string $texto = null): array
    {
        $consulta = $this->createQueryBuilder('e')
            ->select('e AS editorial', 'COUNT(r.id) AS recursos')
            ->leftJoin(Recurso::class, 'r', 'WITH', 'r.editorial = e')
            ->leftJoin('e.pais', 'p')->addSelect('p')
            ->groupBy('e.id', 'p.id')
            ->orderBy('e.nombre', 'ASC');
        if (null !== $texto && '' !== $texto) {
            $consulta->andWhere('e.nombre LIKE :texto')->setParameter('texto', '%'.addcslashes($texto, '%_\\').'%');
        }

        return array_map(fn (array $fila) => ['editorial' => $fila['editorial'], 'recursos' => (int) $fila['recursos']], $consulta->getQuery()->getResult());
    }

    /** @return list<string> */
    public function nombres(): array
    {
        return array_column($this->createQueryBuilder('e')->select('e.nombre')->orderBy('e.nombre')->getQuery()->getScalarResult(), 'nombre');
    }

    /** Busca por nombre sin distinguir mayúsculas ni espacios repetidos. */
    public function porNombre(string $nombre): ?Editorial
    {
        $nombre = trim((string) preg_replace('/\s+/u', ' ', $nombre));

        return $this->createQueryBuilder('e')
            ->where('LOWER(e.nombre) = :nombre')->setParameter('nombre', mb_strtolower($nombre))
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function cantidadRecursos(Editorial $editorial): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(r.id)')->from(Recurso::class, 'r')
            ->where('r.editorial = :editorial')->setParameter('editorial', $editorial)
            ->getQuery()->getSingleScalarResult();
    }
}
