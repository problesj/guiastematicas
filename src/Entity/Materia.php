<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MateriaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Área temática (carrera, disciplina o asignatura). Organiza guías, bases de datos y especialistas.
 */
#[ORM\Entity(repositoryClass: MateriaRepository::class)]
#[UniqueEntity(fields: ['slug'], message: 'materia.slug_repetido')]
class Materia
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $nombre = '';

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $nombreEn = null;

    #[ORM\Column(length: 160, unique: true)]
    #[Assert\Length(max: 160)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'materia.slug_formato')]
    private string $slug = '';

    /** Código opcional de la carrera o asignatura en la UCN. */
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $codigo = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $activa = true;

    #[ORM\ManyToMany(targetEntity: Usuario::class, mappedBy: 'materias')]
    private Collection $especialistas;

    public function __construct()
    {
        $this->especialistas = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): static
    {
        $this->nombre = trim($nombre);

        return $this;
    }

    public function getNombreEn(): ?string
    {
        return $this->nombreEn;
    }

    public function setNombreEn(?string $nombreEn): static
    {
        $this->nombreEn = $nombreEn;

        return $this;
    }

    public function getNombreTraducido(string $idioma): string
    {
        return 'en' === $idioma && $this->nombreEn ? $this->nombreEn : $this->nombre;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = (string) $slug;

        return $this;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function setCodigo(?string $codigo): static
    {
        $this->codigo = $codigo;

        return $this;
    }

    public function isActiva(): bool
    {
        return $this->activa;
    }

    public function setActiva(bool $activa): static
    {
        $this->activa = $activa;

        return $this;
    }

    /** @return Collection<int, Usuario> */
    public function getEspecialistas(): Collection
    {
        return $this->especialistas;
    }

    public function __toString(): string
    {
        return $this->nombre;
    }
}
