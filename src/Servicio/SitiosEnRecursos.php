<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Entity\Guia;
use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Repository\RecursoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Incorpora a la lista de recursos bibliográficos (A-Z), como recursos de tipo Sitio web, los enlaces del bloque
 * "Lista de enlaces" que el editor marcó con «Agregar a recursos bibliográficos». El sitio se reconoce por su dirección
 * (en cualquier tipo de recurso); si no está, se crea de acceso abierto, sin proxy y con las materias de la guía. Un
 * recurso que ya existía no se modifica. Al desmarcar un enlace solo se deja de vincular: el recurso sigue en la lista.
 */
final class SitiosEnRecursos
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecursoRepository $recursos,
    ) {
    }

    /**
     * @param list<array{titulo: ?string, url: ?string, descripcion: ?string, en_recursos: bool, recurso: ?int}> $enlaces
     *
     * @return list<array<string, mixed>> los mismos enlaces con "recurso" (id del recurso vinculado o null)
     */
    public function incorporar(array $enlaces, Guia $guia): array
    {
        $vinculados = [];
        $creados = [];
        foreach ($enlaces as $n => $enlace) {
            $url = $enlace['url'] ?? null;
            if (!($enlace['en_recursos'] ?? false) || null === $url || null === ($enlace['titulo'] ?? null)) {
                $vinculados[$n] = null;
                continue;
            }
            $anterior = \is_int($enlace['recurso'] ?? null) ? $this->recursos->find($enlace['recurso']) : null;
            $recurso = null !== $anterior && $anterior->getUrl() === $url ? $anterior : $this->recursos->findOneBy(['url' => $url]);
            $recurso ??= $creados[mb_strtolower($url)] ?? null;
            if (null === $recurso) {
                $recurso = (new Recurso())
                    ->setTitulo(mb_substr($enlace['titulo'], 0, 200))
                    ->setUrl(mb_substr($url, 0, 1000))
                    ->setDescripcion($enlace['descripcion'] ?? null)
                    ->setTipo(TipoRecurso::SitioWeb)
                    ->setAcceso(TipoAcceso::Abierto)
                    ->setRequiereProxy(false);
                foreach ($guia->getMaterias() as $materia) {
                    $recurso->addMateria($materia);
                }
                $this->em->persist($recurso);
                $creados[mb_strtolower($url)] = $recurso;
            }
            $vinculados[$n] = $recurso;
        }
        $this->em->flush();

        return array_map(
            fn (array $enlace, ?Recurso $recurso) => ['recurso' => $recurso?->getId(), 'en_recursos' => null !== $recurso] + $enlace,
            $enlaces,
            $vinculados,
        );
    }
}
