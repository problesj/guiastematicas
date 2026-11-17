<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TipoBloque;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Bloque de contenido de una sección. Lo que guarda depende del tipo (columna JSON "contenido"):
 *
 * - texto: html (ya saneado)
 * - recursos: ids (lista ordenada de recursos), descripcion (bool: mostrar la descripción de cada uno)
 * - perfil: usuario (id del bibliotecario)
 * - video: url (YouTube o Vimeo), descripcion
 * - busqueda: texto (ayuda sobre el buscador)
 *
 * Un bloque puede ser un vínculo a otro bloque (origen), por lo general de otra guía: muestra el título y el contenido
 * del original, así que lo que se cambie allí se ve en todas las guías que lo usan. Solo se vinculan bloques marcados
 * como reutilizables o los de una guía de contenido común, y siempre al original (nunca a otro vínculo).
 */
#[ORM\Entity]
class Bloque
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'bloques')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Seccion $seccion = null;

    #[ORM\Column(length: 20, enumType: TipoBloque::class)]
    private TipoBloque $tipo;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $titulo = null;

    #[ORM\Column]
    private int $posicion = 0;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $contenido = [];

    /** Permite que otras guías lo vinculen o copien. */
    #[ORM\Column(options: ['default' => false])]
    private bool $compartible = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Bloque $origen = null;

    public function __construct(TipoBloque $tipo)
    {
        $this->tipo = $tipo;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeccion(): ?Seccion
    {
        return $this->seccion;
    }

    public function setSeccion(?Seccion $seccion): static
    {
        $this->seccion = $seccion;

        return $this;
    }

    public function getGuia(): ?Guia
    {
        return $this->seccion?->getGuia();
    }

    public function getTipo(): TipoBloque
    {
        return $this->origen?->getTipo() ?? $this->tipo;
    }

    public function getTitulo(): ?string
    {
        return null !== $this->origen ? $this->origen->getTitulo() : $this->titulo;
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

    /** @return array<string, mixed> */
    public function getContenido(): array
    {
        return $this->origen?->getContenido() ?? $this->contenido;
    }

    /** @param array<string, mixed> $contenido */
    public function setContenido(array $contenido): static
    {
        $this->contenido = $contenido;

        return $this;
    }

    public function dato(string $clave, mixed $omision = null): mixed
    {
        return $this->getContenido()[$clave] ?? $omision;
    }

    public function getOrigen(): ?Bloque
    {
        return $this->origen;
    }

    public function isVinculo(): bool
    {
        return null !== $this->origen;
    }

    /** Vincula este bloque (nuevo) al original de otro: si el otro ya es un vínculo, al bloque del que viene. */
    public function vincularA(Bloque $otro): static
    {
        $this->origen = $otro->origen ?? $otro;
        $this->tipo = $this->origen->tipo;
        $this->titulo = null;
        $this->contenido = [];
        $this->compartible = false;

        return $this;
    }

    /** Convierte un vínculo en un bloque propio con una copia del contenido actual del original. */
    public function desvincular(): static
    {
        if (null !== $this->origen) {
            $this->titulo = $this->origen->titulo;
            $this->contenido = $this->origen->contenido;
            $this->origen = null;
        }

        return $this;
    }

    /** Copia independiente (sin sección ni posición); la copia de un vínculo sigue vinculada al mismo original. */
    public function copiar(): self
    {
        $copia = new self($this->tipo);
        $copia->titulo = $this->titulo;
        $copia->contenido = $this->contenido;
        $copia->origen = $this->origen;

        return $copia;
    }

    public function isCompartible(): bool
    {
        return $this->compartible;
    }

    public function setCompartible(bool $compartible): static
    {
        $this->compartible = $compartible && null === $this->origen;

        return $this;
    }

    /** Se puede vincular o copiar desde otras guías: marcado como reutilizable o de una guía de contenido común. */
    public function isReutilizable(): bool
    {
        return null === $this->origen && ($this->compartible || true === $this->getGuia()?->isComun());
    }
}
