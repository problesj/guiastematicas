<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Recurso;
use App\Entity\Sugerencia;
use App\Entity\Usuario;
use App\Enum\TipoBloque;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Elimina una materia propia traspasando lo que la tenía (recursos, guías, especialistas, sugerencias y bloques de
 * búsqueda) a otra materia: a una categoría ASJC al homologar, o a cualquier otra (propia o ASJC) al eliminar.
 */
final class HomologadorMaterias
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Cuántos elementos tienen la materia (para avisar antes de homologar).
     *
     * @return array{recursos: int, guias: int, especialistas: int, sugerencias: int, bloques: int}
     */
    public function contar(Materia $materia): array
    {
        return [
            'recursos' => \count($this->con(Recurso::class, $materia)),
            'guias' => \count($this->con(Guia::class, $materia)),
            'especialistas' => \count($this->con(Usuario::class, $materia)),
            'sugerencias' => \count($this->con(Sugerencia::class, $materia)),
            'bloques' => \count($this->bloquesCon($materia)),
        ];
    }

    /**
     * Homologa una materia propia a una categoría ASJC (ver traspasar()).
     *
     * @return array{recursos: int, guias: int, especialistas: int, sugerencias: int, bloques: int} elementos cambiados
     */
    public function homologar(Materia $propia, Materia $categoria): array
    {
        if ($propia->isBase() || !$categoria->isBase()) {
            throw new \InvalidArgumentException('Solo se homologa una materia propia a una categoría ASJC.');
        }

        return $this->traspasar($propia, $categoria);
    }

    /**
     * Elimina una materia propia pasando lo que la tenía a otra materia (propia o ASJC); sin destino, solo se le
     * quita. Las categorías ASJC no se eliminan.
     *
     * @return array{recursos: int, guias: int, especialistas: int, sugerencias: int, bloques: int} elementos cambiados
     */
    public function traspasar(Materia $origen, ?Materia $destino): array
    {
        if ($origen->isBase() || $destino === $origen) {
            throw new \InvalidArgumentException('Solo se elimina una materia propia, hacia otra materia.');
        }
        $cambios = ['recursos' => 0, 'guias' => 0, 'especialistas' => 0, 'sugerencias' => 0, 'bloques' => 0];

        $clases = ['recursos' => Recurso::class, 'guias' => Guia::class, 'especialistas' => Usuario::class, 'sugerencias' => Sugerencia::class];
        foreach ($clases as $clave => $clase) {
            foreach ($this->con($clase, $origen) as $elemento) {
                $elemento->removeMateria($origen);
                if (null !== $destino) {
                    $elemento->addMateria($destino);
                }
                ++$cambios[$clave];
            }
        }

        // Bloques «Buscar en el catálogo» con materias elegidas a mano (ids en el contenido).
        foreach ($this->bloquesCon($origen) as $bloque) {
            $ids = array_map('intval', (array) $bloque->dato('materias'));
            $ids = array_map(fn (int $id) => $id === $origen->getId() ? $destino?->getId() : $id, $ids);
            $bloque->setContenido(['materias' => array_values(array_unique(array_filter($ids, fn ($id) => null !== $id)))] + $bloque->getContenido());
            ++$cambios['bloques'];
        }
        $this->em->remove($origen);
        $this->em->flush();

        return $cambios;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $clase
     *
     * @return list<T>
     */
    private function con(string $clase, Materia $materia): array
    {
        return $this->em->createQueryBuilder()->select('x')->from($clase, 'x')->join('x.materias', 'm')
            ->where('m = :materia')->setParameter('materia', $materia)->getQuery()->getResult();
    }

    /** @return list<Bloque> */
    private function bloquesCon(Materia $materia): array
    {
        $bloques = $this->em->getRepository(Bloque::class)->findBy(['tipo' => TipoBloque::Busqueda]);

        return array_values(array_filter($bloques, fn (Bloque $b) => \is_array($b->dato('materias')) && \in_array($materia->getId(), array_map('intval', $b->dato('materias')), true)));
    }
}
