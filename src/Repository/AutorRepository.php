<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Autor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Autor>
 */
class AutorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Autor::class);
    }

    /** @return list<Autor> */
    public function listado(?string $texto = null, int $limite = 500): array
    {
        $consulta = $this->createQueryBuilder('a')
            ->leftJoin('a.pais', 'p')->addSelect('p')
            ->orderBy('a.apellidos', 'ASC')->addOrderBy('a.nombres', 'ASC')
            ->setMaxResults($limite);
        if (null !== $texto && '' !== trim($texto)) {
            self::filtrar($consulta, $texto);
        }

        return $consulta->getQuery()->getResult();
    }

    /**
     * Sugerencias para el selector de autores del formulario de recursos.
     *
     * @return list<Autor>
     */
    public function buscar(string $texto, int $limite = 15): array
    {
        $consulta = $this->createQueryBuilder('a')
            ->orderBy('a.apellidos', 'ASC')->addOrderBy('a.nombres', 'ASC')
            ->setMaxResults($limite);
        self::filtrar($consulta, $texto);

        return $consulta->getQuery()->getResult();
    }

    /** Busca un autor por nombre exacto, sin distinguir mayúsculas. */
    public function porNombre(string $apellidos, string $nombres): ?Autor
    {
        return $this->createQueryBuilder('a')
            ->where('LOWER(a.apellidos) = :apellidos AND LOWER(a.nombres) = :nombres')
            ->setParameter('apellidos', mb_strtolower($apellidos))
            ->setParameter('nombres', mb_strtolower($nombres))
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private static function filtrar(QueryBuilder $consulta, string $texto): void
    {
        // Cada palabra debe aparecer en nombres, apellidos u ORCID ("perez ana" encuentra "Pérez, Ana").
        foreach (array_slice(preg_split('/[\s,]+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $i => $palabra) {
            $consulta->andWhere("a.apellidos LIKE :p$i OR a.nombres LIKE :p$i OR a.orcid LIKE :p$i")
                ->setParameter("p$i", '%'.addcslashes($palabra, '%_\\').'%');
        }
    }
}
