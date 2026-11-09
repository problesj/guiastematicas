<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Materia;
use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Recurso>
 */
class RecursoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recurso::class);
    }

    /**
     * Recursos de la lista pública A-Z.
     *
     * @return list<Recurso>
     */
    public function buscarPublicos(
        ?string $letra = null,
        ?string $texto = null,
        ?Materia $materia = null,
        ?TipoRecurso $tipo = null,
        ?TipoAcceso $acceso = null,
    ): array {
        $consulta = $this->disponibles();

        if (null !== $letra) {
            $consulta->andWhere('r.letra = :letra')->setParameter('letra', $letra);
        }
        if (null !== $texto && '' !== trim($texto)) {
            self::filtrarTexto($consulta, $texto);
        }
        if (null !== $materia) {
            $consulta->andWhere(':materia MEMBER OF r.materias')->setParameter('materia', $materia);
        }
        if (null !== $tipo) {
            $consulta->andWhere('r.tipo = :tipo')->setParameter('tipo', $tipo);
        }
        if (null !== $acceso) {
            $consulta->andWhere('r.acceso = :acceso')->setParameter('acceso', $acceso);
        }

        return $consulta->leftJoin('r.materias', 'm')->addSelect('m')
            ->orderBy('r.letra', 'ASC')
            ->addOrderBy('r.titulo', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Letras que tienen al menos un recurso público, para el índice A-Z.
     *
     * @return list<string>
     */
    public function letrasConRecursos(): array
    {
        return array_column(
            $this->disponibles()->select('DISTINCT r.letra')->orderBy('r.letra')->getQuery()->getScalarResult(),
            'letra',
        );
    }

    /** @return list<Recurso> */
    public function listadoAdministracion(?string $texto = null): array
    {
        $consulta = $this->createQueryBuilder('r')->orderBy('r.titulo', 'ASC');
        if (null !== $texto && '' !== trim($texto)) {
            self::filtrarTexto($consulta, $texto);
        }

        return $consulta->getQuery()->getResult();
    }

    /** Busca un recurso existente para la importación: primero por URL, luego por título. */
    public function buscarParaImportar(string $url, string $titulo): ?Recurso
    {
        return $this->findOneBy(['url' => $url])
            ?? $this->createQueryBuilder('r')
                ->where('LOWER(r.titulo) = :titulo')->setParameter('titulo', mb_strtolower(trim($titulo)))
                ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** Suma un clic sin cargar la entidad (atómico frente a visitas simultáneas). */
    public function registrarClic(Recurso $recurso): void
    {
        $this->createQueryBuilder('r')
            ->update()->set('r.clics', 'r.clics + 1')
            ->where('r.id = :id')->setParameter('id', $recurso->getId())
            ->getQuery()->execute();
    }

    private function disponibles(): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->where('r.visible = true')
            ->andWhere('r.acceso <> :prueba OR r.pruebaHasta IS NULL OR r.pruebaHasta >= :hoy')
            ->setParameter('prueba', TipoAcceso::Prueba)
            ->setParameter('hoy', new \DateTimeImmutable('today'), 'date_immutable');
    }

    private static function filtrarTexto(QueryBuilder $consulta, string $texto): void
    {
        $consulta->andWhere('r.titulo LIKE :texto OR r.nombresAlternativos LIKE :texto OR r.descripcion LIKE :texto OR r.proveedor LIKE :texto'
            .' OR r.autores LIKE :texto OR r.editorial LIKE :texto OR r.isbn LIKE :texto OR r.eisbn LIKE :texto OR r.issn LIKE :texto OR r.eissn LIKE :texto OR r.codigoNorma LIKE :texto')
            ->setParameter('texto', '%'.addcslashes(trim($texto), '%_\\').'%');
    }
}
