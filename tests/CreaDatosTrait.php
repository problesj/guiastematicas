<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Departamento;
use App\Entity\Materia;
use App\Entity\Usuario;
use App\Enum\Rol;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Ayudas para crear registros en las pruebas (cada prueba se revierte con DAMA DoctrineTestBundle). */
trait CreaDatosTrait
{
    protected const string CONTRASENA = 'una-clave-de-prueba-larga';

    protected static function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected static function crearUsuario(
        string $email,
        Rol $rol = Rol::Editor,
        ?string $contrasena = self::CONTRASENA,
        ?string $ldap = null,
        bool $activo = true,
        bool $visible = true,
        string $nombre = 'Ana',
        string $apellidos = 'Pérez',
    ): Usuario {
        $usuario = (new Usuario())
            ->setEmail($email)->setNombre($nombre)->setApellidos($apellidos)->setRol($rol)
            ->setLdapUsuario($ldap)->setActivo($activo)->setVisibleEnDirectorio($visible)
            ->setSlug(strtolower(strtr($email, ['@' => '-', '.' => '-'])));
        if (null !== $contrasena) {
            $usuario->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($usuario, $contrasena));
        }
        static::em()->persist($usuario);
        static::em()->flush();

        return $usuario;
    }

    protected static function crearMateria(string $nombre, string $slug): Materia
    {
        $materia = (new Materia())->setNombre($nombre)->setSlug($slug);
        static::em()->persist($materia);
        static::em()->flush();

        return $materia;
    }

    protected static function crearDepartamento(string $nombre, int $orden = 0): Departamento
    {
        $departamento = (new Departamento())->setNombre($nombre)->setOrden($orden);
        static::em()->persist($departamento);
        static::em()->flush();

        return $departamento;
    }
}
