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
 * Homologa una materia propia a una categoría ASJC: lo que tenía la propia (recursos, guías, especialistas,
 * sugerencias y bloques de búsqueda) pasa a la categoría, y la propia se elimina.
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
     * @return array{recursos: int, guias: int, especialistas: int, sugerencias: int, bloques: int} elementos cambiados
     */
    public function homologar(Materia $propia, Materia $categoria): array
    {
        if ($propia->isBase() || !$categoria->isBase()) {
            throw new \InvalidArgumentException('Solo se homologa una materia propia a una categoría ASJC.');
        }
        $cambios = ['recursos' => 0, 'guias' => 0, 'especialistas' => 0, 'sugerencias' => 0, 'bloques' => 0];

        $clases = ['recursos' => Recurso::class, 'guias' => Guia::class, 'especialistas' => Usuario::class, 'sugerencias' => Sugerencia::class];
        foreach ($clases as $clave => $clase) {
            foreach ($this->con($clase, $propia) as $elemento) {
                $elemento->removeMateria($propia);
                $elemento->addMateria($categoria);
                ++$cambios[$clave];
            }
        }

        // Bloques «Buscar en el catálogo» con materias elegidas a mano (ids en el contenido).
        foreach ($this->bloquesCon($propia) as $bloque) {
            $ids = array_map('intval', (array) $bloque->dato('materias'));
            $ids = array_values(array_unique(array_map(fn (int $id) => $id === $propia->getId() ? (int) $categoria->getId() : $id, $ids)));
            $bloque->setContenido(['materias' => $ids] + $bloque->getContenido());
            ++$cambios['bloques'];
        }
        $this->em->remove($propia);
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
