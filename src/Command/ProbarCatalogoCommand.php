<?php

declare(strict_types=1);

namespace App\Command;

use App\Servicio\Catalogo;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Comprueba la conexión con Primo VE y Alma buscando un ISBN (no muestra las claves):
 *   php bin/console app:probar-catalogo 9780262033848
 */
#[AsCommand(name: 'app:probar-catalogo', description: 'Busca un ISBN en Primo VE y su disponibilidad en Alma')]
final class ProbarCatalogoCommand
{
    public function __construct(private readonly Catalogo $catalogo)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('ISBN de un libro que tenga la biblioteca')] string $isbn): int
    {
        if (!$this->catalogo->buscaPorIsbn()) {
            $io->error('Faltan EXLIBRIS_API_URL, PRIMO_API_KEY o PRIMO_VID en .env.local.');

            return Command::FAILURE;
        }
        $libro = $this->catalogo->libroPorIsbn($isbn);
        if (null === $libro) {
            $io->warning('Primo VE no devolvió ese ISBN (o no respondió: revisa var/log).');

            return Command::FAILURE;
        }
        $io->definitionList(...array_map(fn ($clave, $valor) => [$clave => (string) $valor], array_keys($libro), $libro));
        $io->writeln('Registro: '.$this->catalogo->enlaceRegistro($libro['registro'], 'es'));

        if (!$this->catalogo->informaDisponibilidad()) {
            $io->note('Sin ALMA_API_KEY: no se informa disponibilidad.');
        } elseif (null !== $libro['mms']) {
            $ejemplares = $this->catalogo->disponibilidad([$libro['mms']])[$libro['mms']] ?? null;
            $io->writeln('Disponibilidad (Alma): '.(null === $ejemplares ? 'sin respuesta' : json_encode($ejemplares, \JSON_UNESCAPED_UNICODE)));
        }
        $io->success('El catálogo responde.');

        return Command::SUCCESS;
    }
}
