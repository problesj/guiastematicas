<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Repository\RecursoRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Incorpora los libros del bloque "Libros" a la lista general de recursos bibliográficos (A-Z) y les vincula materias.
 *
 * Cada libro se busca en la lista por ISBN (en sus formas de 10 y 13 dígitos, impreso o electrónico), por DOI, por su
 * enlace o, si no trae identificadores, por título entre los libros. Si no está, se crea como recurso de tipo Libros con
 * los datos del bloque. Si ya estaba, no se tocan sus datos (pueden estar revisados en el panel de recursos): solo las
 * materias.
 *
 * Materias: el formulario del libro muestra las del recurso vinculado, así que en un libro que ya estaba vinculado la
 * selección las reemplaza (se conservan las materias inactivas, que el formulario no muestra). En un libro recién
 * agregado la selección se suma a las que tenga el recurso, y un recurso nuevo sin materias elegidas toma las de la guía.
 */
final class LibrosEnRecursos
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecursoRepository $recursos,
        private readonly RegistroBibliografico $registro,
        private readonly Catalogo $catalogo,
        private readonly BloquesGuia $bloques,
    ) {
    }

    /**
     * Vincula cada libro con su recurso (lo crea si hace falta) y guarda los recursos nuevos.
     *
     * @param list<array<string, mixed>>  $libros   libros del bloque, ya completados y normalizados
     * @param list<iterable<Materia>|null> $materias materias elegidas para cada libro, en el mismo orden (null = sin campo)
     *
     * @return list<array<string, mixed>> los mismos libros con la clave "recurso" (id del recurso o null)
     */
    public function incorporar(array $libros, array $materias, Guia $guia): array
    {
        $vinculados = [];
        $creados = []; // recursos nuevos de este guardado, por clave: un libro repetido en el bloque no se duplica
        foreach ($libros as $n => $libro) {
            $anterior = \is_int($libro['recurso'] ?? null) ? $this->recursos->find($libro['recurso']) : null;
            $recurso = null !== $anterior && self::coincide($anterior, $libro) ? $anterior : $this->buscar($libro);
            $claves = self::claves($libro);
            foreach ($claves as $clave) {
                $recurso ??= $creados[$clave] ?? null;
            }
            $nuevo = null === $recurso;
            $recurso ??= $this->crear($libro);
            if ($nuevo && null !== $recurso) {
                $creados += array_fill_keys($claves, $recurso);
            }
            if (null !== $recurso) {
                $elegidas = self::lista($materias[$n] ?? null);
                if ($nuevo) {
                    foreach ([] === $elegidas ? $guia->getMaterias() : $elegidas as $materia) {
                        $recurso->addMateria($materia);
                    }
                } elseif ($recurso === $anterior && null !== ($materias[$n] ?? null)) {
                    $this->reemplazarMaterias($recurso, $elegidas);
                } else {
                    // Libro recién vinculado con un recurso que ya existía (o sin selección): se suman.
                    foreach ($elegidas as $materia) {
                        $recurso->addMateria($materia);
                    }
                }
            }
            $vinculados[] = $recurso;
        }
        $this->em->flush();

        return array_map(
            fn (array $libro, ?Recurso $recurso) => ['recurso' => $recurso?->getId()] + $libro,
            $libros,
            $vinculados,
        );
    }

    /** Recurso de la lista que corresponde al libro, o null si no está. */
    public function buscar(array $libro): ?Recurso
    {
        $isbn = self::texto($libro['isbn'] ?? null);
        if (null !== $isbn) {
            $variantes = self::variantesIsbn($isbn);
            $id = $this->em->getConnection()->fetchOne(
                "SELECT id FROM recurso WHERE REPLACE(isbn, '-', '') IN (:v) OR REPLACE(eisbn, '-', '') IN (:v) ORDER BY id LIMIT 1",
                ['v' => $variantes],
                ['v' => ArrayParameterType::STRING],
            );
            if (false !== $id) {
                return $this->recursos->find((int) $id);
            }
        }
        $doi = self::texto($libro['doi'] ?? null);
        if (null !== $doi && null !== $recurso = $this->recursos->findOneBy(['doi' => $doi])) {
            return $recurso;
        }
        $enlace = self::texto($libro['enlace'] ?? null);
        if (null !== $enlace && null !== $recurso = $this->recursos->findOneBy(['url' => $enlace])) {
            return $recurso;
        }
        $titulo = self::texto($libro['titulo'] ?? null);
        if (null === $isbn && null === $doi && null !== $titulo) {
            return $this->recursos->createQueryBuilder('r')
                ->where('r.tipo = :tipo')->setParameter('tipo', TipoRecurso::Libros)
                ->andWhere('LOWER(r.titulo) = :titulo')->setParameter('titulo', mb_strtolower(mb_substr($titulo, 0, 200)))
                ->orderBy('r.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        }

        return null;
    }

    /**
     * Recurso nuevo de tipo Libros con los datos del bloque (sin guardar aún). Null si no hay título o no hay a dónde
     * enlazarlo (sin enlace, registro en el catálogo, DOI ni buscador del catálogo).
     */
    private function crear(array $libro): ?Recurso
    {
        $titulo = self::texto($libro['titulo'] ?? null);
        $enlace = self::texto($libro['enlace'] ?? null);
        $doi = self::texto($libro['doi'] ?? null);
        $isbn = self::texto($libro['isbn'] ?? null);
        $registro = self::texto($libro['registro'] ?? null);
        // Un enlace propio o un DOI suelen llevar a la plataforma de la editorial (pasa por el proxy); el catálogo no.
        $url = $enlace ?? (null !== $doi ? 'https://doi.org/'.$doi : null);
        $proxy = null !== $url;
        $url ??= (null !== $registro ? $this->catalogo->enlaceRegistro($registro, 'es') : null)
            ?? $this->bloques->enlacePrimo($isbn ?? (string) $titulo, 'es');
        if (null === $titulo || null === $url) {
            return null;
        }

        $anio = (int) ($libro['anio'] ?? 0);
        $recurso = (new Recurso())
            ->setTitulo(mb_substr($titulo, 0, 200))
            ->setTipo(TipoRecurso::Libros)
            ->setAcceso(TipoAcceso::Suscripcion)
            ->setUrl(mb_substr($url, 0, 1000))
            ->setRequiereProxy($proxy)
            ->setIsbn($isbn)
            ->setDoi($doi)
            ->setAnio($anio >= 1450 && $anio <= 2100 ? $anio : null)
            ->setAutores($this->registro->autoresDesdeTexto(self::texto($libro['autor'] ?? null)))
            ->setEditorial($this->registro->editorial(self::texto($libro['editorial'] ?? null)));
        $this->em->persist($recurso);

        return $recurso;
    }

    /** Las materias elegidas reemplazan a las activas del recurso; las inactivas (que no se ofrecen) se conservan. */
    private function reemplazarMaterias(Recurso $recurso, array $elegidas): void
    {
        foreach ($recurso->getMaterias()->toArray() as $materia) {
            if ($materia->isActiva() && !\in_array($materia, $elegidas, true)) {
                $recurso->removeMateria($materia);
            }
        }
        foreach ($elegidas as $materia) {
            $recurso->addMateria($materia);
        }
    }

    /** El libro sigue siendo el mismo recurso si su ISBN o DOI (cuando los tiene) son los del recurso. */
    private static function coincide(Recurso $recurso, array $libro): bool
    {
        $isbn = self::texto($libro['isbn'] ?? null);
        if (null !== $isbn) {
            $delRecurso = array_filter([$recurso->getIsbn(), $recurso->getEisbn()]);

            return [] !== array_intersect(self::variantesIsbn($isbn), array_map(fn (string $i) => str_replace('-', '', $i), $delRecurso));
        }
        $doi = self::texto($libro['doi'] ?? null);

        return null === $doi || 0 === strcasecmp($doi, (string) $recurso->getDoi());
    }

    /**
     * El ISBN sin guiones y su equivalente de 10 o 13 dígitos (978…).
     *
     * @return list<string>
     */
    public static function variantesIsbn(string $isbn): array
    {
        $isbn = strtoupper((string) preg_replace('/[^0-9Xx]/', '', $isbn));
        $variantes = [$isbn];
        if (1 === preg_match('/^\d{9}[\dX]$/', $isbn)) {
            $base = '978'.substr($isbn, 0, 9);
            $variantes[] = $base.self::digitoIsbn13($base);
        } elseif (1 === preg_match('/^978(\d{9})\d$/', $isbn, $m)) {
            $suma = 0;
            foreach (str_split($m[1]) as $i => $d) {
                $suma += (10 - $i) * (int) $d;
            }
            $control = (11 - $suma % 11) % 11;
            $variantes[] = $m[1].(10 === $control ? 'X' : (string) $control);
        }

        return $variantes;
    }

    private static function digitoIsbn13(string $doce): string
    {
        $suma = 0;
        foreach (str_split($doce) as $i => $d) {
            $suma += (0 === $i % 2 ? 1 : 3) * (int) $d;
        }

        return (string) ((10 - $suma % 10) % 10);
    }

    /** @return list<string> claves con que se reconoce un libro aún no guardado */
    private static function claves(array $libro): array
    {
        $isbn = self::texto($libro['isbn'] ?? null);
        $claves = null !== $isbn ? array_map(fn (string $v) => 'isbn:'.$v, self::variantesIsbn($isbn)) : [];
        foreach (['doi', 'enlace'] as $campo) {
            if (null !== $valor = self::texto($libro[$campo] ?? null)) {
                $claves[] = $campo.':'.mb_strtolower($valor);
            }
        }
        if ([] === $claves && null !== $titulo = self::texto($libro['titulo'] ?? null)) {
            $claves[] = 'titulo:'.mb_strtolower($titulo);
        }

        return $claves;
    }

    /** @return list<Materia> */
    private static function lista(?iterable $materias): array
    {
        $lista = [];
        foreach ($materias ?? [] as $materia) {
            if ($materia instanceof Materia) {
                $lista[] = $materia;
            }
        }

        return $lista;
    }

    private static function texto(mixed $valor): ?string
    {
        $valor = \is_scalar($valor) ? trim((string) $valor) : '';

        return '' === $valor ? null : $valor;
    }
}
