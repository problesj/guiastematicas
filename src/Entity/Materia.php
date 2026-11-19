<?php

declare(strict_types=1);

namespace App\Entity;

use App\Clasificacion\Asjc;
use App\Repository\MateriaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Área temática. Las materias base son las categorías ASJC de Scopus (codigoAsjc); las propias de la UCN (carreras,
 * asignaturas) quedan clasificadas en una o más de esas categorías, así que se encuentran al filtrar por ellas.
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

    /**
     * Categorías ASJC de Scopus a las que corresponde la materia (códigos de cuatro dígitos; ver App\Clasificacion\Asjc).
     *
     * @var list<int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $asjc = [];

    /** Categoría ASJC que representa esta materia base (null en las materias propias). */
    #[ORM\Column(nullable: true, unique: true)]
    private ?int $codigoAsjc = null;

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

    /**
     * Categorías ASJC de la materia: la suya si es una materia base; si es propia, en las que está clasificada.
     *
     * @return list<int>
     */
    public function getAsjc(): array
    {
        return null !== $this->codigoAsjc ? [$this->codigoAsjc] : Asjc::normalizar($this->asjc);
    }

    public function getCodigoAsjc(): ?int
    {
        return $this->codigoAsjc;
    }

    public function setCodigoAsjc(?int $codigo): static
    {
        $this->codigoAsjc = null !== $codigo && Asjc::existe($codigo) ? $codigo : null;
        if (null !== $this->codigoAsjc) {
            $this->asjc = [$this->codigoAsjc];
        }

        return $this;
    }

    /** Materia base (categoría ASJC), no propia. */
    public function isBase(): bool
    {
        return null !== $this->codigoAsjc;
    }

    /** Campo ASJC (XX00) en que se agrupa la materia; 0 si es propia y aún no está clasificada. */
    public function getCampoAsjc(): int
    {
        $codigos = $this->getAsjc();

        return [] === $codigos ? 0 : Asjc::campo($codigos[0]);
    }

    /** @param iterable<int|string> $codigos */
    public function setAsjc(iterable $codigos): static
    {
        $this->asjc = Asjc::normalizar($codigos);

        return $this;
    }

    /**
     * Grandes áreas ASJC de la materia, en el orden de Asjc::AREAS.
     *
     * @return list<string>
     */
    public function getAreas(): array
    {
        $areas = array_map(fn (int $c) => Asjc::area($c), $this->getAsjc());

        return array_values(array_filter(Asjc::AREAS, fn (string $a) => \in_array($a, $areas, true)));
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
