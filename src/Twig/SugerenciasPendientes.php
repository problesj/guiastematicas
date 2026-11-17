<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Usuario;
use App\Repository\SugerenciaRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/** Cantidad de sugerencias pendientes que puede revisar quien está conectado (para el menú del panel). */
final class SugerenciasPendientes
{
    private ?int $cantidad = null;

    public function __construct(private readonly SugerenciaRepository $sugerencias, private readonly Security $security)
    {
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
