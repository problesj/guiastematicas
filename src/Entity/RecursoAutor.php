<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Autor de un recurso, con su posición en la lista (el orden de los autores importa en una cita). */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_recurso_autor', columns: ['recurso_id', 'autor_id'])]
class RecursoAutor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $posicion;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'autorias')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Recurso $recurso,
        #[ORM\ManyToOne(inversedBy: 'autorias', cascade: ['persist'])]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Autor $autor,
        int $posicion = 0,
    ) {
        $this->posicion = $posicion;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecurso(): Recurso
    {
        return $this->recurso;
    }

    public function getAutor(): Autor
    {
        return $this->autor;
    }

    public function getPosicion(): int
    {
        return $this->posicion;
    }

    public function setPosicion(int $posicion): static
    {
        $this->posicion = $posicion;

        return $this;
    }
}
