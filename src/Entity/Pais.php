<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaisRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/** País o territorio (código ISO 3166-1 alfa-2), usado por autores y editoriales. */
#[ORM\Entity(repositoryClass: PaisRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_pais_codigo', columns: ['codigo'])]
#[UniqueEntity(fields: ['codigo'], message: 'pais.codigo_repetido')]
class Pais
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[A-Z]{2}$/', message: 'pais.codigo_no_valido')]
    private string $codigo = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $nombre = '';

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $nombreEn = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodigo(): string
    {
        return $this->codigo;
    }

    public function setCodigo(?string $codigo): static
    {
        $this->codigo = strtoupper(trim((string) $codigo));

        return $this;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

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
        $nombreEn = null === $nombreEn ? null : trim($nombreEn);
        $this->nombreEn = '' === $nombreEn ? null : $nombreEn;

        return $this;
    }

    public function getNombreTraducido(string $idioma): string
    {
        return 'en' === $idioma && $this->nombreEn ? $this->nombreEn : $this->nombre;
    }

    public function __toString(): string
    {
        return $this->nombre;
    }
}
