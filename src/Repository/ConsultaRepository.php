<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Consulta;
use App\Entity\Usuario;
use App\Paginacion\Pagina;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Consulta> */
class ConsultaRepository extends ServiceEntityRepository
{
    public const array FILTROS = ['pendientes', 'respondidas', 'cerradas'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Consulta::class);
    }

    /**
     * @param Usuario|null $bibliotecario null: todas (administradores)
     *
     * @return Pagina<Consulta>
     */
    public function bandeja(?Usuario $bibliotecario, string $filtro, int $pagina, int $porPagina): Pagina
    {
        $consulta = $this->filtrar($bibliotecario, $filtro)
            ->orderBy('c.actualizadaEn', 'pendientes' === $filtro ? 'ASC' : 'DESC')
            ->addOrderBy('c.id', 'ASC');

        return Pagina::desdeConsulta($consulta, $pagina, $porPagina);
    }

    public function contarPendientes(Usuario $bibliotecario): int
    {
        return (int) $this->filtrar($bibliotecario, 'pendientes')->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
    }

    private function filtrar(?Usuario $bibliotecario, string $filtro): QueryBuilder
    {
        $consulta = $this->createQueryBuilder('c');
        if (null !== $bibliotecario) {
            $consulta->andWhere('c.bibliotecario = :bibliotecario')->setParameter('bibliotecario', $bibliotecario);
        }

        return match ($filtro) {
            'cerradas' => $consulta->andWhere('c.cerrada = true'),
            'respondidas' => $consulta->andWhere('c.cerrada = false AND c.pendiente = false'),
            default => $consulta->andWhere('c.cerrada = false AND c.pendiente = true'),
        };
    }
}
