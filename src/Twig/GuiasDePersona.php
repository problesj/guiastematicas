<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Usuario;
use App\Repository\GuiaRepository;
use Twig\Attribute\AsTwigFunction;

/** Guías públicas que edita (responsable) o en que colabora (coeditor) cada bibliotecario, para el directorio y su ficha. */
final class GuiasDePersona
{
    /** @var array<int, array{editor: list<\App\Entity\Guia>, colaborador: list<\App\Entity\Guia>}>|null */
    private ?array $mapa = null;

    public function __construct(private readonly GuiaRepository $guias)
    {
    }

    /** @return array{editor: list<\App\Entity\Guia>, colaborador: list<\App\Entity\Guia>} */
    #[AsTwigFunction('guias_de_persona')]
    public function de(Usuario $persona): array
    {
        $this->mapa ??= $this->guias->publicasPorParticipante();

        return $this->mapa[$persona->getId()] ?? ['editor' => [], 'colaborador' => []];
    }
}
