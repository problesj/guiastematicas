<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pestaña (o página del menú lateral) de una guía. Puede ser subpestaña de otra (un solo nivel, como las subpáginas de
 * LibGuides) o un enlace externo que se abre en otra ventana en vez de tener contenido propio.
 */
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

    /** Pestaña principal de la que depende (subpestaña). Solo un nivel. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Pestana $padre = null;

    /** Si tiene enlace, la pestaña lleva a esa dirección (otra ventana) y no muestra contenido. */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 500)]
    private ?string $enlace = null;

    /** Muestra la etiqueta "Nuevo" junto al título. */
    #[ORM\Column(options: ['default' => false])]
    private bool $nueva = false;

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

    public function getPadre(): ?Pestana
    {
        return $this->padre;
    }

    public function setPadre(?Pestana $padre): static
    {
        $this->padre = $padre;

        return $this;
    }

    public function isPrincipal(): bool
    {
        return null === $this->padre;
    }

    /** @return list<Pestana> subpestañas en orden */
    public function getSubpestanas(): array
    {
        return array_values(array_filter($this->guia?->getPestanas()->toArray() ?? [], fn (Pestana $p) => $p->getPadre() === $this));
    }

    public function getEnlace(): ?string
    {
        return $this->enlace;
    }

    public function setEnlace(?string $enlace): static
    {
        $enlace = trim((string) $enlace);
        $this->enlace = '' === $enlace ? null : $enlace;

        return $this;
    }

    public function isEnlaceExterno(): bool
    {
        return null !== $this->enlace;
    }

    public function isNueva(): bool
    {
        return $this->nueva;
    }

    public function setNueva(bool $nueva): static
    {
        $this->nueva = $nueva;

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
