<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Departamento;
use App\Entity\Materia;
use App\Entity\Recurso;
use App\Entity\Usuario;
use App\Enum\Rol;
use App\Enum\Sede;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
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

        foreach ([
            ['Scopus', 'https://www.scopus.com', TipoRecurso::BaseDatos, TipoAcceso::Suscripcion, 'Elsevier', [0, 1, 4], 'Base de datos de resúmenes y citas de literatura científica revisada por pares.'],
            ['Web of Science', 'https://www.webofscience.com', TipoRecurso::BaseDatos, TipoAcceso::Suscripcion, 'Clarivate', [0, 1, 3], 'Índice de citas multidisciplinario.'],
            ['PubMed', 'https://pubmed.ncbi.nlm.nih.gov', TipoRecurso::BaseDatos, TipoAcceso::Abierto, 'NLM', [1], 'Literatura biomédica de la Biblioteca Nacional de Medicina de EE. UU.'],
            ['SciELO Chile', 'https://www.scielo.cl', TipoRecurso::Revistas, TipoAcceso::Abierto, 'SciELO', [1, 2, 3], 'Revistas científicas chilenas de acceso abierto.'],
            ['vLex', 'https://vlex.cl', TipoRecurso::BaseDatos, TipoAcceso::Suscripcion, 'vLex', [2], 'Legislación, jurisprudencia y doctrina.'],
            ['Springer eBooks', 'https://link.springer.com', TipoRecurso::Libros, TipoAcceso::Suscripcion, 'Springer Nature', [0, 3, 4], 'Libros electrónicos de ciencia y tecnología.'],
            ['ASTM Compass', 'https://compass.astm.org', TipoRecurso::Normas, TipoAcceso::Prueba, 'ASTM', [0], 'Normas técnicas ASTM.'],
            ['GeoRef', 'https://www.americangeosciences.org/georef', TipoRecurso::BaseDatos, TipoAcceso::Suscripcion, 'AGI', [4], 'Bibliografía de geociencias.'],
            ['Zotero', 'https://www.zotero.org', TipoRecurso::Herramienta, TipoAcceso::Abierto, null, [], 'Gestor de referencias bibliográficas gratuito.'],
        ] as [$titulo, $url, $tipo, $acceso, $proveedor, $indices, $descripcion]) {
            $recurso = (new Recurso())->setTitulo($titulo)->setUrl($url)->setTipo($tipo)->setAcceso($acceso)
                ->setProveedor($proveedor)->setDescripcion($descripcion)->setRequiereProxy(TipoAcceso::Abierto !== $acceso);
            if (TipoAcceso::Prueba === $acceso) {
                $recurso->setPruebaHasta(new \DateTimeImmutable('+30 days'));
            }
            foreach ($indices as $i) {
                $recurso->addMateria($materias[$i]);
            }
            $manager->persist($recurso);
        }

        $manager->flush();
    }
}
