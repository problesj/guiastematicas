<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Sugerencia;
use App\Entity\Usuario;
use App\Repository\SugerenciaRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * SUGERENCIA_REVISAR: administradores, y responsables o coeditores de una guía que comparte
 * alguna materia con la sugerencia (si ninguna guía la comparte, solo administradores).
 *
 * @extends Voter<string, Sugerencia>
 */
final class SugerenciaVoter extends Voter
{
    public const string REVISAR = 'SUGERENCIA_REVISAR';

    public function __construct(private readonly Security $security, private readonly SugerenciaRepository $sugerencias)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::REVISAR === $attribute && $subject instanceof Sugerencia;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $usuario = $token->getUser();
        if (!$usuario instanceof Usuario || !$this->security->isGranted('ROLE_COLABORADOR')) {
            return false;
        }

        return $this->security->isGranted('ROLE_ADMINISTRADOR') || $this->sugerencias->puedeRevisar($usuario, $subject);
    }
}
