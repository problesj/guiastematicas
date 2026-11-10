<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Autor;
use App\Entity\Bloque;
use App\Entity\Departamento;
use App\Entity\Editorial;
use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Pais;
use App\Entity\Pestana;
use App\Entity\Recurso;
use App\Entity\Seccion;
use App\Entity\Usuario;
use App\Enum\DisposicionGuia;
use App\Enum\Rol;
use App\Enum\Sede;
use App\Enum\Sexo;
use App\Enum\TipoAcceso;
use App\Enum\TipoBloque;
use App\Enum\TipoRecurso;
use App\Servicio\CatalogoPaises;
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
        $usuarios = [];
        $recursos = [];
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
            $usuarios[$email] = $usuario;
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
            $recursos[$titulo] = $recurso;
        }

        $paises = [];
        foreach (CatalogoPaises::PAISES as $codigo => [$nombre, $nombreEn]) {
            $paises[$codigo] = (new Pais())->setCodigo($codigo)->setNombre($nombre)->setNombreEn($nombreEn);
            $manager->persist($paises[$codigo]);
        }

        $autores = [
            (new Autor())->setApellidos('Pérez Rojas')->setNombres('Ana María')->setSexo(Sexo::Femenino)->setPais($paises['CL'])->setOrcid('0000-0002-1825-0097'),
            (new Autor())->setApellidos('Soto')->setNombres('Luis')->setSexo(Sexo::Masculino)->setPais($paises['CL']),
        ];
        $editorial = (new Editorial())->setNombre('Ediciones Universidad Católica del Norte')->setPais($paises['CL']);
        $libro = (new Recurso())->setTitulo('Introducción a la geología del norte de Chile')->setUrl('https://ebooks.ucn.cl/geologia')
            ->setTipo(TipoRecurso::Libros)->setAcceso(TipoAcceso::Suscripcion)->setAnio(2021)->setIsbn('978-956-11-2345-8')
            ->setEditorial($editorial)->setAutores($autores)->addMateria($materias[4]);
        $manager->persist($libro);

        $manager->flush();

        $this->guiasDeEjemplo($manager, $usuarios, $recursos, $materias);
        $manager->flush();
    }

    /**
     * Una guía publicada en pestañas (con traducción al inglés en borrador) y una en borrador con menú lateral.
     *
     * @param array<string, Usuario> $usuarios
     * @param array<string, Recurso> $recursos
     * @param list<Materia>          $materias
     */
    private function guiasDeEjemplo(ObjectManager $manager, array $usuarios, array $recursos, array $materias): void
    {
        $bloque = static fn (TipoBloque $tipo, ?string $titulo, array $contenido) => (new Bloque($tipo))->setTitulo($titulo)->setContenido($contenido);
        $ids = static fn (string ...$titulos) => array_map(fn (string $t) => $recursos[$t]->getId(), $titulos);

        $ingenieria = (new Guia())->setTitulo('Ingeniería: cómo buscar información')->setSlug('ingenieria')
            ->setDescripcion('Bases de datos, normas técnicas y libros electrónicos para estudiantes de ingeniería.')
            ->setDisposicion(DisposicionGuia::Pestanas)->setResponsable($usuarios['editora1@ucn.cl'])
            ->addCoeditor($usuarios['editora3@ucn.cl'])->addMateria($materias[0]);

        $inicio = new Pestana('Inicio');
        $inicio->agregarSeccion((new Seccion('Bienvenida'))->setColumnas(2)
            ->agregarBloque($bloque(TipoBloque::Texto, null, ['html' => '<p>Esta guía reúne los recursos de información más útiles para <strong>Ingeniería</strong>. Si necesitas ayuda, escríbenos o agenda una capacitación.</p>']))
            ->agregarBloque($bloque(TipoBloque::Perfil, 'Tu bibliotecaria', ['usuario' => $usuarios['editora1@ucn.cl']->getId()])));
        $inicio->agregarSeccion((new Seccion('Busca en el catálogo'))
            ->agregarBloque($bloque(TipoBloque::Busqueda, null, ['texto' => 'Busca libros, artículos y tesis en el catálogo de Bibliotecas UCN.'])));
        $ingenieria->agregarPestana($inicio);

        $bases = new Pestana('Bases de datos');
        $bases->agregarSeccion((new Seccion('Multidisciplinarias'))
            ->agregarBloque($bloque(TipoBloque::Recursos, null, ['ids' => $ids('Scopus', 'Web of Science'), 'descripcion' => true])));
        $bases->agregarSeccion((new Seccion('Normas y libros'))
            ->agregarBloque($bloque(TipoBloque::Recursos, null, ['ids' => $ids('ASTM Compass', 'Springer eBooks'), 'descripcion' => false])));
        $ingenieria->agregarPestana($bases);

        $citar = new Pestana('Cómo citar');
        $citar->agregarSeccion((new Seccion())
            ->agregarBloque($bloque(TipoBloque::Texto, 'Normas de citación', ['html' => '<p>Las normas más usadas en ingeniería son <strong>IEEE</strong> y <strong>APA</strong>.</p><ul><li>IEEE: referencias numeradas.</li><li>APA: autor y año.</li></ul>']))
            ->agregarBloque($bloque(TipoBloque::Recursos, 'Gestor de referencias', ['ids' => $ids('Zotero'), 'descripcion' => true])));
        $ingenieria->agregarPestana($citar);

        $apa = (new Pestana('Normas APA'))->setPadre($citar)->setNueva(true);
        $apa->agregarSeccion((new Seccion())
            ->agregarBloque($bloque(TipoBloque::Texto, 'APA 7.ª edición', ['html' => '<p>Cita con autor y año en el texto y ordena la lista de referencias alfabéticamente.</p>'])));
        $ingenieria->agregarPestana($apa);
        $ingenieria->agregarPestana((new Pestana('Aprende'))->setEnlace('https://bibliotecas.ucn.cl/'));
        $ingenieria->publicar();
        $manager->persist($ingenieria);

        $engineering = (new Guia())->setTitulo('Engineering: finding information')->setSlug('engineering')->setIdioma('en')
            ->setDisposicion(DisposicionGuia::Pestanas)->setResponsable($usuarios['editora1@ucn.cl'])->addMateria($materias[0])
            ->agregarPestana((new Pestana('Home'))->agregarSeccion((new Seccion())
                ->agregarBloque($bloque(TipoBloque::Texto, null, ['html' => '<p>Key information resources for Engineering.</p>']))));
        $engineering->setTraduccion($ingenieria);
        $manager->persist($engineering);

        $geologia = (new Guia())->setTitulo('Geología')->setSlug('geologia')
            ->setDescripcion('Recursos de geociencias y minería.')
            ->setDisposicion(DisposicionGuia::MenuLateral)->setResponsable($usuarios['editora1@ucn.cl'])->addMateria($materias[4])
            ->agregarPestana((new Pestana('Inicio'))->agregarSeccion((new Seccion('Bases de datos'))
                ->agregarBloque($bloque(TipoBloque::Recursos, null, ['ids' => $ids('GeoRef', 'Scopus'), 'descripcion' => true]))))
            ->agregarPestana((new Pestana('Videos'))->agregarSeccion((new Seccion())
                ->agregarBloque($bloque(TipoBloque::Video, 'Cómo buscar en GeoRef', ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'descripcion' => 'Tutorial de búsqueda básica.']))));
        $manager->persist($geologia);
    }
}
