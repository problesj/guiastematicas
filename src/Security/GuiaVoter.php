<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Guia;
use App\Entity\Usuario;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permisos sobre una guía:
 * - GUIA_EDITAR (contenido: pestañas, secciones, bloques): administradores, el responsable y los coeditores.
 * - GUIA_GESTIONAR (publicar, datos, coeditores, eliminar): administradores y el responsable si es editor.
 *
 * @extends Voter<string, Guia>
 */
final class GuiaVoter extends Voter
{
    public const string EDITAR = 'GUIA_EDITAR';
    public const string GESTIONAR = 'GUIA_GESTIONAR';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDITAR, self::GESTIONAR], true) && $subject instanceof Guia;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $usuario = $token->getUser();
        if (!$usuario instanceof Usuario) {
            return false;
        }
        if ($this->security->isGranted('ROLE_ADMINISTRADOR')) {
            return true;
        }

        return match ($attribute) {
            self::EDITAR => $subject->puedeEditar($usuario),
            self::GESTIONAR => $subject->getResponsable() === $usuario && $this->security->isGranted('ROLE_EDITOR'),
        };
    }
}
