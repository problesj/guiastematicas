<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Usuario;
use App\Enum\EstadoGuia;
use App\Enum\VisibilidadGuia;
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
    public function publicadas(?Materia $materia = null, ?string $texto = null, ?Usuario $lector = null, bool $todas = false, ?array $deMaterias = null): array
    {
        $consulta = $this->createQueryBuilder('g')
            ->leftJoin('g.materias', 'm')->addSelect('m')
            ->leftJoin('g.responsable', 'u')->addSelect('u')
            ->where('g.estado = :publicada')->setParameter('publicada', EstadoGuia::Publicada)
            ->orderBy('g.titulo', 'ASC');
        // Las restringidas solo aparecen a quien puede verlas ($todas: administradores).
        if (!$todas) {
            if (null === $lector) {
                $consulta->andWhere('g.visibilidad = :publica');
            } else {
                $consulta->andWhere('g.visibilidad = :publica OR g.responsable = :lector OR :lector MEMBER OF g.coeditores OR :lector MEMBER OF g.lectores')
                    ->setParameter('lector', $lector);
            }
            $consulta->setParameter('publica', VisibilidadGuia::Publica);
        }
        if (null !== $materia) {
            // Una categoría ASJC incluye las guías de las materias propias clasificadas en ella.
            $consulta->andWhere('g.id IN (SELECT gm.id FROM '.Guia::class.' gm JOIN gm.materias mm WHERE mm IN (:materia))')
                ->setParameter('materia', $this->getEntityManager()->getRepository(Materia::class)->conClasificadas($materia));
        }
        // Gran área ASJC: guías con alguna de las materias del área (lista vacía: ninguna).
        if (null !== $deMaterias) {
            $consulta->andWhere([] === $deMaterias ? '1 = 0' : 'g.id IN (SELECT ga.id FROM '.Guia::class.' ga JOIN ga.materias ma WHERE ma IN (:deMaterias))');
            if ([] !== $deMaterias) {
                $consulta->setParameter('deMaterias', $deMaterias);
            }
        }
        if (null !== $texto && '' !== trim($texto)) {
            $consulta->andWhere('g.titulo LIKE :texto OR g.descripcion LIKE :texto')
                ->setParameter('texto', '%'.addcslashes(trim($texto), '%_\\').'%');
        }

        return $consulta->getQuery()->getResult();
    }

    /**
     * Guías publicadas y públicas en que participa cada persona, para el directorio de bibliotecarios.
     *
     * @return array<int, array{editor: list<Guia>, colaborador: list<Guia>}> por id de usuario
     */
    public function publicasPorParticipante(): array
    {
        $guias = $this->createQueryBuilder('g')
            ->leftJoin('g.coeditores', 'c')->addSelect('c')
            ->where('g.estado = :publicada')->setParameter('publicada', EstadoGuia::Publicada)
            ->andWhere('g.visibilidad = :publica')->setParameter('publica', VisibilidadGuia::Publica)
            ->orderBy('g.titulo', 'ASC')
            ->getQuery()->getResult();

        $mapa = [];
        foreach ($guias as $guia) {
            if (null !== $guia->getResponsable()?->getId()) {
                $mapa[$guia->getResponsable()->getId()]['editor'][] = $guia;
            }
            foreach ($guia->getCoeditores() as $coeditor) {
                $mapa[$coeditor->getId()]['colaborador'][] = $guia;
            }
        }

        return array_map(fn (array $g) => $g + ['editor' => [], 'colaborador' => []], $mapa);
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
