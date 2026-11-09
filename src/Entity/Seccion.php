<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Sección de una pestaña: un título opcional y bloques de contenido. */
#[ORM\Entity]
class Seccion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'secciones')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pestana $pestana = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $titulo = null;

    #[ORM\Column]
    private int $posicion = 0;

    /** @var Collection<int, Bloque> */
    #[ORM\OneToMany(targetEntity: Bloque::class, mappedBy: 'seccion', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['posicion' => 'ASC'])]
    private Collection $bloques;

    public function __construct(?string $titulo = null)
    {
        $this->bloques = new ArrayCollection();
        $this->setTitulo($titulo);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPestana(): ?Pestana
    {
        return $this->pestana;
    }

    public function setPestana(?Pestana $pestana): static
    {
        $this->pestana = $pestana;

        return $this;
    }

    public function getGuia(): ?Guia
    {
        return $this->pestana?->getGuia();
    }

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }

    public function setTitulo(?string $titulo): static
    {
        $titulo = null === $titulo ? null : trim($titulo);
        $this->titulo = '' === $titulo ? null : $titulo;

        return $this;
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

    /** @return Collection<int, Bloque> */
    public function getBloques(): Collection
    {
        return $this->bloques;
    }

    public function agregarBloque(Bloque $bloque): static
    {
        $bloque->setSeccion($this)->setPosicion($this->bloques->count());
        $this->bloques->add($bloque);

        return $this;
    }

    public function removeBloque(Bloque $bloque): static
    {
        $this->bloques->removeElement($bloque);

        return $this;
    }
}
