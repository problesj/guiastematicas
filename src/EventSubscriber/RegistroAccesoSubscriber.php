<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** Guarda la fecha del último acceso (y cualquier cambio hecho al autenticar, como el id de Google). */
#[AsEventListener]
final class RegistroAccesoSubscriber
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(LoginSuccessEvent $evento): void
    {
        $usuario = $evento->getUser();
        if ($usuario instanceof Usuario) {
            $usuario->registrarAcceso();
            $this->em->flush();
        }
    }
}
