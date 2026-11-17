<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Usuario;
use App\Repository\ConsultaRepository;
use App\Repository\SugerenciaRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/** Contadores del menú del panel: sugerencias que puede revisar y consultas que esperan su respuesta. */
final class SugerenciasPendientes
{
    private ?int $cantidad = null;

    public function __construct(
        private readonly SugerenciaRepository $sugerencias,
        private readonly ConsultaRepository $consultas,
        private readonly Security $security,
    ) {
    }

    /** Consultas de la ficha pública que esperan respuesta de quien está conectado. */
    #[AsTwigFunction('consultas_pendientes')]
    public function consultas(): int
    {
        $usuario = $this->security->getUser();

        return $usuario instanceof Usuario && $this->security->isGranted('ROLE_COLABORADOR') ? $this->consultas->contarPendientes($usuario) : 0;
    }

    #[AsTwigFunction('sugerencias_pendientes')]
    public function contar(): int
    {
        $usuario = $this->security->getUser();
        if (!$usuario instanceof Usuario || !$this->security->isGranted('ROLE_COLABORADOR')) {
            return 0;
        }

        return $this->cantidad ??= $this->sugerencias->contarPendientes($this->security->isGranted('ROLE_ADMINISTRADOR') ? null : $usuario);
    }
}
