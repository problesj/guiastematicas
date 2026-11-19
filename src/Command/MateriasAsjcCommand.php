<?php

declare(strict_types=1);

namespace App\Command;

use App\Clasificacion\SincronizadorAsjc;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:materias-asjc', description: 'Crea las materias base, una por categoría ASJC de Scopus (se puede repetir).')]
final class MateriasAsjcCommand extends Command
{
    public function __construct(private readonly Connection $conexion)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resultado = SincronizadorAsjc::sincronizar($this->conexion);
        (new SymfonyStyle($input, $output))->success(\sprintf('Materias ASJC: %d creadas, %d existentes reutilizadas.', $resultado['creadas'], $resultado['unidas']));

        return Command::SUCCESS;
    }
}
