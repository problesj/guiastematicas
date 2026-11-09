<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Sede;
use App\Repository\DepartamentoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Unidad de las bibliotecas (p. ej. "Referencia", "Biblioteca de Medicina") que agrupa al personal en el directorio. */
#[ORM\Entity(repositoryClass: DepartamentoRepository::class)]
class Departamento
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $nombre = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $nombreEn = null;

    #[ORM\Column(length: 20, nullable: true, enumType: Sede::class)]
    private ?Sede $sede = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $telefono = null;

    /** Orden en el directorio (menor primero). */
    #[ORM\Column(options: ['default' => 0])]
    #[Assert\Range(min: 0, max: 999)]
    private int $orden = 0;

    #[ORM\OneToMany(targetEntity: Usuario::class, mappedBy: 'departamento')]
    #[ORM\OrderBy(['apellidos' => 'ASC', 'nombre' => 'ASC'])]
    private Collection $integrantes;

    public function __construct()
    {
        $this->integrantes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    /** Acepta null (campo vacío en un formulario); la validación NotBlank informa el error. */
    public function setNombre(?string $nombre): static
    {
        $this->nombre = trim((string) $nombre);

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

    public function getSede(): ?Sede
    {
        return $this->sede;
    }

    public function setSede(?Sede $sede): static
    {
        $this->sede = $sede;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    public function setTelefono(?string $telefono): static
    {
        $this->telefono = $telefono;

        return $this;
    }

    public function getOrden(): int
    {
        return $this->orden;
    }

    public function setOrden(int $orden): static
    {
        $this->orden = $orden;

        return $this;
    }

    /** @return Collection<int, Usuario> */
    public function getIntegrantes(): Collection
    {
        return $this->integrantes;
    }

    public function __toString(): string
    {
        return $this->nombre;
    }
}
