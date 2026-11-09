<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Usuario;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Impide el acceso a cuentas desactivadas, sea cual sea el método de inicio de sesión. */
final class VerificadorUsuario implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof Usuario && !$user->isActivo()) {
            throw new CustomUserMessageAccountStatusException('acceso.cuenta_inactiva');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
