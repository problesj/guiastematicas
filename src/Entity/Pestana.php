<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;

/** Pestaña (o página del menú lateral) de una guía. */
#[ORM\Entity]
class Pestana
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'pestanas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Guia $guia = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $titulo = '';

    /** Parte de la dirección: /es/guias/{guia}/{pestana}. Único dentro de la guía. */
    #[ORM\Column(length: 110)]
    private string $slug = '';

    #[ORM\Column]
    private int $posicion = 0;

    /** @var Collection<int, Seccion> */
    #[ORM\OneToMany(targetEntity: Seccion::class, mappedBy: 'pestana', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['posicion' => 'ASC'])]
    private Collection $secciones;

    public function __construct(string $titulo = '')
    {
        $this->secciones = new ArrayCollection();
        $this->setTitulo($titulo);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGuia(): ?Guia
    {
        return $this->guia;
    }

    public function setGuia(?Guia $guia): static
    {
        $this->guia = $guia;

        return $this;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    /** El slug se recalcula con el título, sin repetir otro de la misma guía. */
    public function setTitulo(?string $titulo): static
    {
        $this->titulo = trim((string) $titulo);
        $base = mb_substr((new AsciiSlugger())->slug($this->titulo)->lower()->toString(), 0, 100) ?: 'pestana';
        $slug = $base;
        for ($n = 2; null !== $this->guia && $this->slugUsado($slug); ++$n) {
            $slug = $base.'-'.$n;
        }
        $this->slug = $slug;

        return $this;
    }

    private function slugUsado(string $slug): bool
    {
        foreach ($this->guia?->getPestanas() ?? [] as $otra) {
            if ($otra !== $this && $otra->getSlug() === $slug) {
                return true;
            }
        }

        return false;
    }

    public function getSlug(): string
    {
        return $this->slug;
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

    /** @return Collection<int, Seccion> */
    public function getSecciones(): Collection
    {
        return $this->secciones;
    }

    public function agregarSeccion(Seccion $seccion): static
    {
        $seccion->setPestana($this)->setPosicion($this->secciones->count());
        $this->secciones->add($seccion);

        return $this;
    }

    public function removeSeccion(Seccion $seccion): static
    {
        $this->secciones->removeElement($seccion);

        return $this;
    }
}
