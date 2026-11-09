<?php

declare(strict_types=1);

namespace App\Servicio;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/** Genera identificadores de URL únicos ("ana-perez", "ana-perez-2") para una entidad con campo slug. */
final class GeneradorSlug
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param class-string $clase
     */
    public function generar(string $clase, string $texto, ?int $idPropio = null): string
    {
        $base = mb_substr($this->slugger->slug($texto)->lower()->toString(), 0, 100) ?: 'sin-nombre';
        $candidato = $base;
        $repositorio = $this->em->getRepository($clase);

        for ($n = 2; ; ++$n) {
            $existente = $repositorio->findOneBy(['slug' => $candidato]);
            if (null === $existente || $existente->getId() === $idPropio) {
                return $candidato;
            }
            $candidato = $base.'-'.$n;
        }
    }
}
