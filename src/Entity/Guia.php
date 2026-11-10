<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DisposicionGuia;
use App\Enum\EstadoGuia;
use App\Repository\GuiaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Guía temática: pestañas, cada una con secciones y bloques de contenido.
 *
 * Cada guía está escrita en un idioma; su versión en el otro idioma (si existe) es otra guía enlazada en "traduccion".
 */
#[ORM\Entity(repositoryClass: GuiaRepository::class)]
#[ORM\Index(name: 'idx_guia_estado', columns: ['estado'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'guia.slug_repetido')]
class Guia
{
    public const array IDIOMAS = ['es', 'en'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $titulo = '';

    /** Dirección pública: /es/guias/{slug}. */
    #[ORM\Column(length: 120, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'guia.slug_no_valido')]
    private string $slug = '';

    /** Resumen breve para la lista de guías y buscadores. */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $descripcion = null;

    #[ORM\Column(length: 2, options: ['default' => 'es'])]
    #[Assert\Choice(choices: self::IDIOMAS)]
    private string $idioma = 'es';

    /** La misma guía en el otro idioma. */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Guia $traduccion = null;

    #[ORM\Column(length: 20, enumType: EstadoGuia::class)]
    private EstadoGuia $estado = EstadoGuia::Borrador;

    #[ORM\Column(length: 20, enumType: DisposicionGuia::class)]
    private DisposicionGuia $disposicion = DisposicionGuia::Pestanas;

    /** Imagen de cabecera (archivo dentro de public/uploads/guias). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $banner = null;

    /** Ícono de la guía en listados y cabecera (archivo dentro de public/uploads/guias). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $icono = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Usuario $responsable = null;

    /** @var Collection<int, Usuario> */
    #[ORM\ManyToMany(targetEntity: Usuario::class)]
    #[ORM\JoinTable(name: 'guia_coeditor')]
    #[ORM\OrderBy(['apellidos' => 'ASC'])]
    private Collection $coeditores;

    /** @var Collection<int, Materia> */
    #[ORM\ManyToMany(targetEntity: Materia::class)]
    #[ORM\OrderBy(['nombre' => 'ASC'])]
    private Collection $materias;

    /** @var Collection<int, Pestana> */
    #[ORM\OneToMany(targetEntity: Pestana::class, mappedBy: 'guia', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['posicion' => 'ASC'])]
    private Collection $pestanas;

    #[ORM\Column]
    private int $visitas = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publicadaEn = null;

    #[ORM\Column]
    private \DateTimeImmutable $creadaEn;

    #[ORM\Column]
    private \DateTimeImmutable $actualizadaEn;

    public function __construct()
    {
        $this->coeditores = new ArrayCollection();
        $this->materias = new ArrayCollection();
        $this->pestanas = new ArrayCollection();
        $this->creadaEn = new \DateTimeImmutable();
        $this->actualizadaEn = $this->creadaEn;
    }

    #[ORM\PreUpdate]
    public function marcarActualizada(): void
    {
        $this->actualizadaEn = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(?string $titulo): static
    {
        $this->titulo = trim((string) $titulo);

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = strtolower(trim((string) $slug));

        return $this;
    }

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): static
    {
        $descripcion = null === $descripcion ? null : trim($descripcion);
        $this->descripcion = '' === $descripcion ? null : $descripcion;

        return $this;
    }

    public function getIdioma(): string
    {
        return $this->idioma;
    }

    public function setIdioma(string $idioma): static
    {
        $this->idioma = $idioma;

        return $this;
    }

    public function getTraduccion(): ?Guia
    {
        return $this->traduccion;
    }

    /** Enlaza ambas guías entre sí (y suelta las traducciones anteriores). */
    public function setTraduccion(?Guia $traduccion): static
    {
        if ($traduccion === $this->traduccion) {
            return $this;
        }
        $anterior = $this->traduccion;
        $this->traduccion = $traduccion;
        if (null !== $anterior && $anterior->traduccion === $this) {
            $anterior->traduccion = null;
        }
        if (null !== $traduccion && $traduccion->traduccion !== $this) {
            $traduccion->setTraduccion($this);
        }

        return $this;
    }

    public function getEstado(): EstadoGuia
    {
        return $this->estado;
    }

    public function isPublicada(): bool
    {
        return EstadoGuia::Publicada === $this->estado;
    }

    public function publicar(): static
    {
        $this->estado = EstadoGuia::Publicada;
        $this->publicadaEn ??= new \DateTimeImmutable();

        return $this;
    }

    public function despublicar(): static
    {
        $this->estado = EstadoGuia::Borrador;

        return $this;
    }

    public function getPublicadaEn(): ?\DateTimeImmutable
    {
        return $this->publicadaEn;
    }

    public function getDisposicion(): DisposicionGuia
    {
        return $this->disposicion;
    }

    public function setDisposicion(DisposicionGuia $disposicion): static
    {
        $this->disposicion = $disposicion;

        return $this;
    }

    public function getBanner(): ?string
    {
        return $this->banner;
    }

    public function setBanner(?string $banner): static
    {
        $this->banner = $banner;

        return $this;
    }

    public function getIcono(): ?string
    {
        return $this->icono;
    }

    public function setIcono(?string $icono): static
    {
        $this->icono = $icono;

        return $this;
    }

    public function getResponsable(): ?Usuario
    {
        return $this->responsable;
    }

    public function setResponsable(?Usuario $responsable): static
    {
        $this->responsable = $responsable;

        return $this;
    }

    /** @return Collection<int, Usuario> */
    public function getCoeditores(): Collection
    {
        return $this->coeditores;
    }

    public function addCoeditor(Usuario $usuario): static
    {
        if (!$this->coeditores->contains($usuario)) {
            $this->coeditores->add($usuario);
        }

        return $this;
    }

    public function removeCoeditor(Usuario $usuario): static
    {
        $this->coeditores->removeElement($usuario);

        return $this;
    }

    /** Responsable y coeditores, sin repetir. */
    public function puedeEditar(Usuario $usuario): bool
    {
        return $usuario === $this->responsable || $this->coeditores->contains($usuario);
    }

    /** @return Collection<int, Materia> */
    public function getMaterias(): Collection
    {
        return $this->materias;
    }

    public function addMateria(Materia $materia): static
    {
        if (!$this->materias->contains($materia)) {
            $this->materias->add($materia);
        }

        return $this;
    }

    public function removeMateria(Materia $materia): static
    {
        $this->materias->removeElement($materia);

        return $this;
    }

    /** @return Collection<int, Pestana> */
    public function getPestanas(): Collection
    {
        return $this->pestanas;
    }

    public function agregarPestana(Pestana $pestana): static
    {
        $pestana->setGuia($this)->setPosicion($this->pestanas->count());
        $this->pestanas->add($pestana);
        $pestana->setTitulo($pestana->getTitulo()); // recalcula el slug sin repetir el de otra pestaña

        return $this;
    }

    public function removePestana(Pestana $pestana): static
    {
        $this->pestanas->removeElement($pestana);

        return $this;
    }

    /** Sin slug, la primera página con contenido (la portada de la guía). */
    public function getPestana(?string $slug = null): ?Pestana
    {
        if (null === $slug) {
            return $this->getPaginas()[0] ?? null;
        }
        foreach ($this->pestanas as $pestana) {
            if ($pestana->getSlug() === $slug) {
                return $pestana;
            }
        }

        return null;
    }

    /** @return list<Pestana> pestañas principales, en orden (las subpestañas cuelgan de ellas) */
    public function getPestanasPrincipales(): array
    {
        return array_values(array_filter($this->pestanas->toArray(), fn (Pestana $p) => $p->isPrincipal()));
    }

    /**
     * Páginas con contenido en el orden de lectura (cada principal seguida de sus subpestañas), sin los enlaces externos.
     * Es el orden de los botones Anterior / Siguiente.
     *
     * @return list<Pestana>
     */
    public function getPaginas(): array
    {
        $paginas = [];
        foreach ($this->getPestanasPrincipales() as $principal) {
            foreach ([$principal, ...$principal->getSubpestanas()] as $pestana) {
                if (!$pestana->isEnlaceExterno()) {
                    $paginas[] = $pestana;
                }
            }
        }

        return $paginas;
    }

    /** @return array{anterior: ?Pestana, siguiente: ?Pestana} */
    public function vecinas(Pestana $pestana): array
    {
        $paginas = $this->getPaginas();
        $i = array_search($pestana, $paginas, true);

        return false === $i ? ['anterior' => null, 'siguiente' => null] : [
            'anterior' => $paginas[$i - 1] ?? null,
            'siguiente' => $paginas[$i + 1] ?? null,
        ];
    }

    public function getVisitas(): int
    {
        return $this->visitas;
    }

    public function getCreadaEn(): \DateTimeImmutable
    {
        return $this->creadaEn;
    }

    public function getActualizadaEn(): \DateTimeImmutable
    {
        return $this->actualizadaEn;
    }

    /** Marca la guía como modificada (al cambiar solo sus pestañas, secciones o bloques). */
    public function tocar(): static
    {
        $this->actualizadaEn = new \DateTimeImmutable();

        return $this;
    }

    public function __toString(): string
    {
        return $this->titulo;
    }
}
