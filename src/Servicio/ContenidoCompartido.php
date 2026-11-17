<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Pestana;
use App\Entity\Seccion;
use App\Enum\TipoBloque;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Bloques y pestañas que se reutilizan entre guías. Un vínculo muestra siempre el contenido actual del original; una
 * copia es independiente. Reutilizables son los marcados así y todos los de las guías de contenido común.
 */
final class ContenidoCompartido
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AlmacenImagenesGuia $imagenes,
    ) {
    }

    /**
     * Bloques reutilizables de las demás guías, filtrados por texto (título del bloque, de la sección o de la guía) y tipo.
     *
     * @return list<Bloque>
     */
    public function bloquesReutilizables(?Guia $excepto, string $texto = '', ?TipoBloque $tipo = null, ?int $idGuia = null): array
    {
        $consulta = $this->em->createQueryBuilder()->select('b', 's', 'p', 'g')->from(Bloque::class, 'b')
            ->join('b.seccion', 's')->join('s.pestana', 'p')->join('p.guia', 'g')
            ->where('b.origen IS NULL')->andWhere('b.compartible = true OR g.comun = true')
            ->orderBy('g.comun', 'DESC')->addOrderBy('g.titulo')->addOrderBy('p.posicion')->addOrderBy('s.posicion')->addOrderBy('b.posicion')
            ->setMaxResults(200);
        if (null !== $excepto) {
            $consulta->andWhere('g <> :excepto')->setParameter('excepto', $excepto);
        }
        if ('' !== trim($texto)) {
            $consulta->andWhere('b.titulo LIKE :texto OR s.titulo LIKE :texto OR g.titulo LIKE :texto')
                ->setParameter('texto', '%'.addcslashes(trim($texto), '%_\\').'%');
        }
        if (null !== $tipo) {
            $consulta->andWhere('b.tipo = :tipo')->setParameter('tipo', $tipo);
        }
        if (null !== $idGuia) {
            $consulta->andWhere('g.id = :guia')->setParameter('guia', $idGuia);
        }

        return $consulta->getQuery()->getResult();
    }

    /** @return list<Pestana> pestañas reutilizables de las demás guías */
    public function pestanasReutilizables(?Guia $excepto = null): array
    {
        $consulta = $this->em->createQueryBuilder()->select('p', 'g')->from(Pestana::class, 'p')->join('p.guia', 'g')
            ->where('p.origen IS NULL')->andWhere('p.enlace IS NULL')->andWhere('p.compartible = true OR g.comun = true')
            ->orderBy('g.comun', 'DESC')->addOrderBy('g.titulo')->addOrderBy('p.posicion');
        if (null !== $excepto) {
            $consulta->andWhere('g <> :excepto')->setParameter('excepto', $excepto);
        }

        return $consulta->getQuery()->getResult();
    }

    /** @return list<Guia> guías que muestran este bloque o pestaña mediante un vínculo (sin repetir) */
    #[AsTwigFunction('guias_que_vinculan')]
    public function guiasQueVinculan(Bloque|Pestana $original): array
    {
        if (null === $original->getId()) {
            return [];
        }
        $vinculos = $this->em->getRepository($original::class)->findBy(['origen' => $original]);
        $guias = [];
        foreach ($vinculos as $vinculo) {
            $guia = $vinculo->getGuia();
            $guias[$guia->getId()] = $guia;
        }

        return array_values($guias);
    }

    /** Agrega a la sección un vínculo al bloque (o una copia independiente). */
    public function reutilizarBloque(Bloque $original, Seccion $seccion, bool $vincular): Bloque
    {
        $nuevo = $vincular ? (new Bloque($original->getTipo()))->vincularA($original) : $original->copiar();
        $seccion->agregarBloque($nuevo);
        $this->imagenes->duplicarDiapositivas($nuevo, $seccion->getGuia());

        return $nuevo;
    }

    /** Agrega a la guía, al final, una pestaña vinculada a otra (o una copia con sus secciones y bloques). */
    public function reutilizarPestana(Pestana $original, Guia $guia, bool $vincular): Pestana
    {
        $nueva = new Pestana();
        $guia->agregarPestana($nueva);
        $nueva->setTitulo($original->getTitulo());
        if ($vincular) {
            $nueva->vincularA($original);
        } else {
            $this->copiarSecciones($original, $nueva);
        }

        return $nueva;
    }

    /** Convierte un bloque vinculado en una copia propia del contenido actual del original. */
    public function desvincularBloque(Bloque $bloque): void
    {
        if ($bloque->isVinculo()) {
            $bloque->desvincular();
            $this->imagenes->duplicarDiapositivas($bloque, $bloque->getGuia());
        }
    }

    /** Convierte una pestaña vinculada en una propia con copias de las secciones y bloques del original. */
    public function desvincularPestana(Pestana $pestana): void
    {
        $original = $pestana->getOrigen();
        if (null !== $original) {
            $pestana->quitarVinculo();
            $this->copiarSecciones($original, $pestana);
        }
    }

    /**
     * Borra los vínculos que apuntan a los bloques y pestañas que se van a eliminar (la base de datos lo hace sola
     * con ON DELETE CASCADE; esto lo asegura también donde no se aplican las claves foráneas).
     */
    public function quitarVinculosA(Guia|Pestana|Seccion|Bloque $elemento): void
    {
        [$pestanas, $secciones] = match (true) {
            $elemento instanceof Guia => [$elemento->getPestanas()->toArray(), []],
            $elemento instanceof Pestana => [[$elemento], []],
            $elemento instanceof Seccion => [[], [$elemento]],
            default => [[], []],
        };
        $bloques = $elemento instanceof Bloque ? [$elemento] : [];
        foreach ($pestanas as $pestana) {
            array_push($secciones, ...$pestana->getSeccionesPropias()->toArray());
        }
        foreach ($secciones as $seccion) {
            array_push($bloques, ...$seccion->getBloques()->toArray());
        }
        $ids = fn (array $lista) => array_values(array_filter(array_map(fn ($e) => $e->getId(), $lista)));

        if ([] !== $ids($bloques)) {
            $this->em->createQuery('DELETE FROM '.Bloque::class.' b WHERE b.origen IN (:ids)')->setParameter('ids', $ids($bloques))->execute();
        }
        if ([] !== $ids($pestanas)) {
            $vinculadas = $this->em->createQuery('SELECT p.id FROM '.Pestana::class.' p WHERE p.origen IN (:ids)')->setParameter('ids', $ids($pestanas))->getSingleColumnResult();
            if ([] !== $vinculadas) {
                $this->em->createQuery('UPDATE '.Pestana::class.' p SET p.padre = NULL WHERE p.padre IN (:ids)')->setParameter('ids', $vinculadas)->execute();
                $this->em->createQuery('DELETE FROM '.Pestana::class.' p WHERE p.id IN (:ids)')->setParameter('ids', $vinculadas)->execute();
            }
        }
    }

    private function copiarSecciones(Pestana $original, Pestana $destino): void
    {
        foreach ($original->getSecciones() as $seccion) {
            $copia = $seccion->copiar();
            $destino->agregarSeccion($copia);
            foreach ($copia->getBloques() as $bloque) {
                $this->imagenes->duplicarDiapositivas($bloque, $destino->getGuia());
            }
        }
        if ($destino->getSeccionesPropias()->isEmpty()) {
            $destino->agregarSeccion(new Seccion());
        }
    }
}
