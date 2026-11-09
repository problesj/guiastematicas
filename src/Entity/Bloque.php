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
        return $this->tipo;
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

    /** @return array<string, mixed> */
    public function getContenido(): array
    {
        return $this->contenido;
    }

    /** @param array<string, mixed> $contenido */
    public function setContenido(array $contenido): static
    {
        $this->contenido = $contenido;

        return $this;
    }

    public function dato(string $clave, mixed $omision = null): mixed
    {
        return $this->contenido[$clave] ?? $omision;
    }
}
