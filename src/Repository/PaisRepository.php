<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Pais;
use App\Servicio\CatalogoPaises;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pais>
 */
class PaisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pais::class);
    }

    /** @return list<Pais> */
    public function ordenados(): array
    {
        return $this->findBy([], ['nombre' => 'ASC']);
    }

    public function porCodigo(string $codigo): ?Pais
    {
        return $this->findOneBy(['codigo' => strtoupper(trim($codigo))]);
    }

    /** Agrega los países del catálogo ISO que falten (no cambia los existentes). Devuelve cuántos agregó. */
    public function completarCatalogo(): int
    {
        $existentes = array_flip(array_map(fn (Pais $p) => $p->getCodigo(), $this->findAll()));
        $agregados = 0;
        foreach (CatalogoPaises::PAISES as $codigo => [$nombre, $nombreEn]) {
            if (!isset($existentes[$codigo])) {
                $this->getEntityManager()->persist((new Pais())->setCodigo($codigo)->setNombre($nombre)->setNombreEn($nombreEn));
                ++$agregados;
            }
        }
        $this->getEntityManager()->flush();

        return $agregados;
    }
}
