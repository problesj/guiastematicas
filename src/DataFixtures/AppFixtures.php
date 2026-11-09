<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Departamento;
use App\Entity\Materia;
use App\Entity\Usuario;
use App\Enum\Rol;
use App\Enum\Sede;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Datos de ejemplo SOLO para desarrollo: php bin/console doctrine:fixtures:load
 * Todas las cuentas usan la contraseña "guias-desarrollo".
 */
final class AppFixtures extends Fixture
{
    public const string CONTRASENA = 'guias-desarrollo';

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $slugger = new AsciiSlugger();

        $departamentos = [];
        foreach ([
            ['Referencia y formación de usuarios', 'Reference and user training', Sede::Antofagasta, 1],
            ['Biblioteca Coquimbo', 'Coquimbo Library', Sede::Coquimbo, 2],
            ['Recursos electrónicos', 'Electronic resources', null, 3],
        ] as [$nombre, $nombreEn, $sede, $orden]) {
            $departamento = (new Departamento())->setNombre($nombre)->setNombreEn($nombreEn)->setSede($sede)->setOrden($orden);
            $manager->persist($departamento);
            $departamentos[] = $departamento;
        }

        $materias = [];
        foreach ([
            ['Ingeniería Civil', 'Civil Engineering'],
            ['Medicina', 'Medicine'],
            ['Derecho', 'Law'],
            ['Biología Marina', 'Marine Biology'],
            ['Geología', 'Geology'],
            ['Arquitectura', 'Architecture'],
        ] as [$nombre, $nombreEn]) {
            $materia = (new Materia())->setNombre($nombre)->setNombreEn($nombreEn)->setSlug($slugger->slug($nombre)->lower()->toString());
            $manager->persist($materia);
            $materias[] = $materia;
        }

        foreach ([
            ['admin@ucn.cl', 'Administración', 'Guías', Rol::Administrador, 0, [], 'Administrador del sistema', false],
            ['editora1@ucn.cl', 'Ana', 'Pérez Rojas', Rol::Editor, 0, [0, 4], 'Bibliotecaria de referencia', true],
            ['editor2@ucn.cl', 'Bruno', 'Soto Araya', Rol::Editor, 1, [3], 'Bibliotecario sede Coquimbo', true],
            ['editora3@ucn.cl', 'Carla', 'Díaz Muñoz', Rol::Editor, 2, [1, 2], 'Encargada de recursos electrónicos', true],
            ['colaborador@ucn.cl', 'Diego', 'Fuentes Vera', Rol::Colaborador, 0, [5], 'Asistente de biblioteca', true],
        ] as [$email, $nombre, $apellidos, $rol, $departamento, $indices, $cargo, $visible]) {
            $usuario = (new Usuario())
                ->setEmail($email)->setNombre($nombre)->setApellidos($apellidos)->setRol($rol)
                ->setDepartamento($departamentos[$departamento])->setCargo($cargo)->setVisibleEnDirectorio($visible)
                ->setSede($departamentos[$departamento]->getSede());
            $usuario->setSlug($slugger->slug($nombre.' '.$apellidos)->lower()->toString());
            $usuario->setPassword($this->hasher->hashPassword($usuario, self::CONTRASENA));
            foreach ($indices as $i) {
                $usuario->addMateria($materias[$i]);
            }
            $manager->persist($usuario);
        }

        $manager->flush();
    }
}
