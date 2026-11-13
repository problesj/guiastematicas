<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Repository\RecursoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Incorpora las revistas del bloque "Revistas" a la lista general de recursos bibliográficos (A-Z), como recursos de
 * tipo Revistas, con las mismas reglas que los libros (ver LibrosEnRecursos): se reconoce la revista por ISSN (impreso
 * o electrónico), por su enlace o por título entre las revistas; si no está, se crea; si ya estaba, solo se tocan sus
 * materias.
 */
final class RevistasEnRecursos
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecursoRepository $recursos,
        private readonly Catalogo $catalogo,
        private readonly BloquesGuia $bloques,
    ) {
    }

    /**
     * @param list<array<string, mixed>>   $revistas revistas del bloque, ya completadas y normalizadas
     * @param list<iterable<Materia>|null> $materias materias elegidas para cada revista, en el mismo orden
     *
     * @return list<array<string, mixed>> las mismas revistas con la clave "recurso" (id del recurso o null)
     */
    public function incorporar(array $revistas, array $materias, Guia $guia): array
    {
        $vinculados = [];
        $creados = [];
        foreach ($revistas as $n => $revista) {
            $anterior = \is_int($revista['recurso'] ?? null) ? $this->recursos->find($revista['recurso']) : null;
            $recurso = null !== $anterior && self::coincide($anterior, $revista) ? $anterior : $this->buscar($revista);
            $claves = self::claves($revista);
            foreach ($claves as $clave) {
                $recurso ??= $creados[$clave] ?? null;
            }
            $nuevo = null === $recurso;
            $recurso ??= $this->crear($revista);
            if ($nuevo && null !== $recurso) {
                $creados += array_fill_keys($claves, $recurso);
            }
            if (null !== $recurso) {
                $elegidas = array_values(array_filter([...($materias[$n] ?? [])], fn ($m) => $m instanceof Materia));
                if ($nuevo) {
                    foreach ([] === $elegidas ? $guia->getMaterias() : $elegidas as $materia) {
                        $recurso->addMateria($materia);
                    }
                } elseif ($recurso === $anterior && null !== ($materias[$n] ?? null)) {
                    foreach ($recurso->getMaterias()->toArray() as $materia) {
                        if ($materia->isActiva() && !\in_array($materia, $elegidas, true)) {
                            $recurso->removeMateria($materia);
                        }
                    }
                    foreach ($elegidas as $materia) {
                        $recurso->addMateria($materia);
                    }
                } else {
                    foreach ($elegidas as $materia) {
                        $recurso->addMateria($materia);
                    }
                }
            }
            $vinculados[] = $recurso;
        }
        $this->em->flush();

        return array_map(
            fn (array $revista, ?Recurso $recurso) => ['recurso' => $recurso?->getId()] + $revista,
            $revistas,
            $vinculados,
        );
    }

    public function buscar(array $revista): ?Recurso
    {
        $issns = self::issns($revista);
        if ([] !== $issns) {
            $recurso = $this->recursos->createQueryBuilder('r')
                ->where('r.issn IN (:issns) OR r.eissn IN (:issns)')->setParameter('issns', $issns)
                ->orderBy('r.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
            if (null !== $recurso) {
                return $recurso;
            }
        }
        $enlace = self::texto($revista['enlace'] ?? null);
        if (null !== $enlace && null !== $recurso = $this->recursos->findOneBy(['url' => $enlace])) {
            return $recurso;
        }
        $titulo = self::texto($revista['titulo'] ?? null);
        if ([] === $issns && null !== $titulo) {
            return $this->recursos->createQueryBuilder('r')
                ->where('r.tipo = :tipo')->setParameter('tipo', TipoRecurso::Revistas)
                ->andWhere('LOWER(r.titulo) = :titulo')->setParameter('titulo', mb_strtolower(mb_substr($titulo, 0, 200)))
                ->orderBy('r.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        }

        return null;
    }

    /** Recurso nuevo de tipo Revistas (sin guardar aún), o null si no hay título ni a dónde enlazarlo. */
    private function crear(array $revista): ?Recurso
    {
        $titulo = self::texto($revista['titulo'] ?? null);
        $enlace = self::texto($revista['enlace'] ?? null);
        $registro = self::texto($revista['registro'] ?? null);
        $issns = self::issns($revista);
        $url = $enlace
            ?? (null !== $registro ? $this->catalogo->enlaceRegistro($registro, 'es') : null)
            ?? $this->bloques->enlacePrimo($issns[0] ?? (string) $titulo, 'es');
        if (null === $titulo || null === $url) {
            return null;
        }

        $recurso = (new Recurso())
            ->setTitulo(mb_substr($titulo, 0, 200))
            ->setTipo(TipoRecurso::Revistas)
            ->setAcceso(TipoAcceso::Suscripcion)
            ->setUrl(mb_substr($url, 0, 1000))
            ->setRequiereProxy(null !== $enlace)
            ->setIssn(Catalogo::issn(self::texto($revista['issn'] ?? null)))
            ->setEissn(Catalogo::issn(self::texto($revista['eissn'] ?? null)))
            ->setProveedor(null !== ($e = self::texto($revista['editorial'] ?? null)) ? mb_substr($e, 0, 200) : null);
        $this->em->persist($recurso);

        return $recurso;
    }

    private static function coincide(Recurso $recurso, array $revista): bool
    {
        $issns = self::issns($revista);

        return [] === $issns || [] !== array_intersect($issns, array_filter([$recurso->getIssn(), $recurso->getEissn()]));
    }

    /** @return list<string> */
    private static function issns(array $revista): array
    {
        return array_values(array_unique(array_filter([
            Catalogo::issn(self::texto($revista['issn'] ?? null)),
            Catalogo::issn(self::texto($revista['eissn'] ?? null)),
        ])));
    }

    /** @return list<string> */
    private static function claves(array $revista): array
    {
        $claves = array_map(fn (string $i) => 'issn:'.$i, self::issns($revista));
        if (null !== $enlace = self::texto($revista['enlace'] ?? null)) {
            $claves[] = 'enlace:'.mb_strtolower($enlace);
        }
        if ([] === $claves && null !== $titulo = self::texto($revista['titulo'] ?? null)) {
            $claves[] = 'titulo:'.mb_strtolower($titulo);
        }

        return $claves;
    }

    private static function texto(mixed $valor): ?string
    {
        $valor = \is_scalar($valor) ? trim((string) $valor) : '';

        return '' === $valor ? null : $valor;
    }
}
