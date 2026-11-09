<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Usuario;
use App\Enum\EstadoGuia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Guia>
 */
class GuiaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Guia::class);
    }

    /**
     * Guías publicadas para la lista pública, opcionalmente de una materia o con un texto.
     *
     * @return list<Guia>
     */
    public function publicadas(?Materia $materia = null, ?string $texto = null): array
    {
        $consulta = $this->createQueryBuilder('g')
            ->leftJoin('g.materias', 'm')->addSelect('m')
            ->leftJoin('g.responsable', 'u')->addSelect('u')
            ->where('g.estado = :publicada')->setParameter('publicada', EstadoGuia::Publicada)
            ->orderBy('g.titulo', 'ASC');
        if (null !== $materia) {
            $consulta->andWhere(':materia MEMBER OF g.materias')->setParameter('materia', $materia);
        }
        if (null !== $texto && '' !== trim($texto)) {
            $consulta->andWhere('g.titulo LIKE :texto OR g.descripcion LIKE :texto')
                ->setParameter('texto', '%'.addcslashes(trim($texto), '%_\\').'%');
        }

        return $consulta->getQuery()->getResult();
    }

    public function publicadaPorSlug(string $slug): ?Guia
    {
        return $this->findOneBy(['slug' => $slug, 'estado' => EstadoGuia::Publicada]);
    }

    /**
     * Guías que una persona puede editar (todas si es administradora).
     *
     * @return list<Guia>
     */
    public function editablesPor(Usuario $usuario, bool $todas, ?string $texto = null): array
    {
        $consulta = $this->createQueryBuilder('g')
            ->leftJoin('g.responsable', 'u')->addSelect('u')
            ->orderBy('g.actualizadaEn', 'DESC');
        if (!$todas) {
            $consulta->andWhere('g.responsable = :usuario OR :usuario MEMBER OF g.coeditores')->setParameter('usuario', $usuario);
        }
        if (null !== $texto && '' !== trim($texto)) {
            $consulta->andWhere('g.titulo LIKE :texto')->setParameter('texto', '%'.addcslashes(trim($texto), '%_\\').'%');
        }

        return $consulta->getQuery()->getResult();
    }

    /** Suma una visita sin cargar la entidad. */
    public function registrarVisita(Guia $guia): void
    {
        $this->createQueryBuilder('g')->update()->set('g.visitas', 'g.visitas + 1')
            ->where('g.id = :id')->setParameter('id', $guia->getId())
            ->getQuery()->execute();
    }
}
