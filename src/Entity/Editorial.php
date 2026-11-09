<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EditorialRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/** Editorial o casa editora de libros y revistas. Distinta del proveedor o plataforma de acceso. */
#[ORM\Entity(repositoryClass: EditorialRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_editorial_nombre', columns: ['nombre'])]
#[UniqueEntity(fields: ['nombre'], message: 'editorial.nombre_repetido')]
class Editorial
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $nombre = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Pais $pais = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setNombre(?string $nombre): static
    {
        $this->nombre = trim((string) preg_replace('/\s+/u', ' ', (string) $nombre));

        return $this;
    }

    public function getPais(): ?Pais
    {
        return $this->pais;
    }

    public function setPais(?Pais $pais): static
    {
        $this->pais = $pais;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nombre;
    }
}
