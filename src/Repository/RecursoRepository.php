<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Materia;
use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Paginacion\Pagina;
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
     * Recursos de la lista pública A-Z, por páginas.
     *
     * @return Pagina<Recurso>
     */
    public function buscarPublicos(
        int $pagina,
        int $porPagina,
        ?string $letra = null,
        ?string $texto = null,
        ?Materia $materia = null,
        ?TipoRecurso $tipo = null,
        ?TipoAcceso $acceso = null,
    ): Pagina {
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

        $consulta->leftJoin('r.materias', 'm')->addSelect('m')
            ->leftJoin('r.autorias', 'ra')->addSelect('ra')
            ->leftJoin('ra.autor', 'au')->addSelect('au')
            ->leftJoin('r.editorial', 'ed')->addSelect('ed')
            ->orderBy('r.letra', 'ASC')
            ->addOrderBy('r.titulo', 'ASC')
            ->addOrderBy('r.id', 'ASC');

        return Pagina::desdeConsulta($consulta, $pagina, $porPagina, true);
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

    /** @return Pagina<Recurso> */
    public function listadoAdministracion(int $pagina, int $porPagina, ?string $texto = null): Pagina
    {
        $consulta = $this->createQueryBuilder('r')->orderBy('r.titulo', 'ASC')->addOrderBy('r.id', 'ASC');
        if (null !== $texto && '' !== trim($texto)) {
            self::filtrarTexto($consulta, $texto);
        }

        return Pagina::desdeConsulta($consulta, $pagina, $porPagina);
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
            .' OR EXISTS (SELECT sra.id FROM App\\Entity\\RecursoAutor sra JOIN sra.autor sau WHERE sra.recurso = r AND (sau.apellidos LIKE :texto OR sau.nombres LIKE :texto OR sau.orcid LIKE :texto))'
            .' OR EXISTS (SELECT sed.id FROM App\\Entity\\Editorial sed WHERE sed = r.editorial AND sed.nombre LIKE :texto)'
            .' OR r.isbn LIKE :texto OR r.eisbn LIKE :texto OR r.issn LIKE :texto OR r.eissn LIKE :texto OR r.codigoNorma LIKE :texto')
            ->setParameter('texto', '%'.addcslashes(trim($texto), '%_\\').'%');
    }
}
