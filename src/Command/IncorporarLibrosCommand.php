<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Bloque;
use App\Enum\TipoBloque;
use App\Servicio\LibrosEnRecursos;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Incorpora a la lista de recursos los libros de los bloques "Libros" guardados antes de que existiera esa función
 * (los que se guardan desde el editor ya se incorporan solos). Se puede ejecutar más de una vez: no duplica recursos.
 *   php bin/console app:incorporar-libros
 */
#[AsCommand(name: 'app:incorporar-libros', description: 'Agrega a la lista de recursos los libros de los bloques Libros')]
final class IncorporarLibrosCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibrosEnRecursos $libros,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $bloques = $this->em->getRepository(Bloque::class)->findBy(['tipo' => TipoBloque::Libros]);
        $vinculados = 0;
        $sinRecurso = 0;
        foreach ($bloques as $bloque) {
            $guia = $bloque->getGuia();
            $items = array_values(array_filter($bloque->dato('items', []), 'is_array'));
            if (null === $guia || [] === $items) {
                continue;
            }
            $items = $this->libros->incorporar($items, array_fill(0, \count($items), null), $guia);
            $bloque->setContenido(['items' => $items] + $bloque->getContenido());
            $this->em->flush();
            foreach ($items as $item) {
                null === $item['recurso'] ? ++$sinRecurso : ++$vinculados;
            }
        }

        $io->success(\sprintf('%d libros vinculados con la lista de recursos en %d bloques.', $vinculados, \count($bloques)));
        if ($sinRecurso > 0) {
            $io->note(\sprintf('%d libros sin título ni enlace quedaron fuera.', $sinRecurso));
        }

        return Command::SUCCESS;
    }
}
