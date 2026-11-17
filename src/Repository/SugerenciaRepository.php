<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guia;
use App\Entity\Recurso;
use App\Entity\Sugerencia;
use App\Entity\Usuario;
use App\Enum\EstadoSugerencia;
use App\Paginacion\Pagina;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Quién revisa cada sugerencia: los administradores ven todas; el resto, las que comparten
 * alguna materia con una guía de la que es responsable o coeditor.
 *
 * @extends ServiceEntityRepository<Sugerencia>
 */
class SugerenciaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sugerencia::class);
    }

    /** @return Pagina<Sugerencia> */
    public function bandeja(?Usuario $revisor, EstadoSugerencia $estado, int $pagina, int $porPagina): Pagina
    {
        $consulta = $this->consulta($revisor, $estado)
            ->orderBy('s.'.(EstadoSugerencia::Pendiente === $estado ? 'creadaEn' : 'decididaEn'), EstadoSugerencia::Pendiente === $estado ? 'ASC' : 'DESC')
            ->addOrderBy('s.id', 'ASC');

        return Pagina::desdeConsulta($consulta, $pagina, $porPagina);
    }

    /** @param Usuario|null $revisor null: administrador (todas) */
    public function contarPendientes(?Usuario $revisor): int
    {
        return (int) $this->consulta($revisor, EstadoSugerencia::Pendiente)
            ->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
    }

    /** true si la persona edita alguna guía que comparte materia con la sugerencia. */
    public function puedeRevisar(Usuario $usuario, Sugerencia $sugerencia): bool
    {
        if (null === $sugerencia->getId()) {
            return false;
        }

        return (bool) $this->consulta($usuario, null)
            ->select('COUNT(s.id)')
            ->andWhere('s = :sugerencia')->setParameter('sugerencia', $sugerencia)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Recursos de la lista A-Z que podrían ser el mismo que se sugiere (misma URL, ISBN/ISSN o título).
     *
     * @return list<Recurso>
     */
    public function posiblesDuplicados(Sugerencia $sugerencia): array
    {
        $consulta = $this->getEntityManager()->getRepository(Recurso::class)->createQueryBuilder('r')
            ->where('LOWER(r.titulo) = :titulo')->setParameter('titulo', mb_strtolower($sugerencia->getTitulo()))
            ->setMaxResults(5);
        if (null !== $sugerencia->getUrl()) {
            $consulta->orWhere('r.url = :url')->setParameter('url', $sugerencia->getUrl());
        }
        if (null !== $sugerencia->getIdentificador()) {
            // Como lo escribió, solo dígitos y con forma de ISSN (los ISBN se guardan con o sin guiones).
            $tal = strtoupper((string) preg_replace('/\s+/', '', $sugerencia->getIdentificador()));
            $digitos = (string) preg_replace('/[^0-9X]/', '', $tal);
            $variantes = array_values(array_unique(array_filter([$tal, $digitos, 8 === \strlen($digitos) ? substr($digitos, 0, 4).'-'.substr($digitos, 4) : null])));
            $consulta->orWhere('r.isbn IN (:identificadores) OR r.eisbn IN (:identificadores) OR r.issn IN (:identificadores) OR r.eissn IN (:identificadores)')
                ->setParameter('identificadores', $variantes);
        }

        return $consulta->getQuery()->getResult();
    }

    private function consulta(?Usuario $revisor, ?EstadoSugerencia $estado): QueryBuilder
    {
        $consulta = $this->createQueryBuilder('s');
        if (null !== $estado) {
            $consulta->andWhere('s.estado = :estado')->setParameter('estado', $estado);
        }
        if (null !== $revisor) {
            $guias = $this->getEntityManager()->createQueryBuilder()
                ->select('1')
                ->from(Guia::class, 'g')
                ->join('g.materias', 'gm')
                ->leftJoin('g.coeditores', 'gc')
                ->where('gm MEMBER OF s.materias')
                ->andWhere('g.responsable = :revisor OR gc = :revisor');
            $consulta->andWhere($consulta->expr()->exists($guias->getDQL()))->setParameter('revisor', $revisor);
        }

        return $consulta;
    }
}
