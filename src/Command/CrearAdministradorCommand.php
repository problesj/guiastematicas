<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Usuario;
use App\Enum\Rol;
use App\Repository\UsuarioRepository;
use App\Servicio\GeneradorSlug;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Crea (o promueve) un administrador. Pensado para el primer acceso en un servidor nuevo:
 *   php bin/console app:crear-administrador correo@ucn.cl "Nombre" "Apellidos"
 * La contraseña se pide de forma oculta; con --sin-contrasena la persona entrará solo con LDAP o Google.
 */
#[AsCommand(name: 'app:crear-administrador', description: 'Crea un administrador o da ese rol a una persona existente')]
final class CrearAdministradorCommand
{
    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly GeneradorSlug $slugs,
        private readonly ValidatorInterface $validador,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Correo de la persona')] string $email,
        #[Argument('Nombre')] string $nombre = '',
        #[Argument('Apellidos')] string $apellidos = '',
        #[Option('Usuario LDAP (uid)')] ?string $ldap = null,
        #[Option('No pedir contraseña propia')] bool $sinContrasena = false,
    ): int {
        $usuario = $this->usuarios->findOneBy(['email' => mb_strtolower(trim($email))]);
        $nuevo = null === $usuario;
        $usuario ??= (new Usuario())->setEmail($email)->setNombre($nombre)->setApellidos($apellidos);
        $usuario->setRol(Rol::Administrador)->setActivo(true);

        if (null !== $ldap) {
            $usuario->setLdapUsuario($ldap);
        }

        if (!$sinContrasena) {
            $contrasena = (string) $io->askHidden('Contraseña (mínimo 12 caracteres)', function (?string $valor): string {
                if (null === $valor || mb_strlen($valor) < 12) {
                    throw new \RuntimeException('La contraseña debe tener al menos 12 caracteres.');
                }

                return $valor;
            });
            $usuario->setPassword($this->hasher->hashPassword($usuario, $contrasena));
        }

        $usuario->setSlug($this->slugs->generar(Usuario::class, $usuario->getNombreCompleto() ?: $email, $usuario->getId()));

        $errores = $this->validador->validate($usuario);
        if (\count($errores) > 0) {
            foreach ($errores as $error) {
                $io->error($error->getPropertyPath().': '.$error->getMessage());
            }

            return Command::FAILURE;
        }

        $this->em->persist($usuario);
        $this->em->flush();
        $io->success(sprintf('%s es administrador%s.', $usuario->getEmail(), $nuevo ? ' (cuenta nueva)' : ''));

        return Command::SUCCESS;
    }
}
