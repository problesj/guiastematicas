<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Sexo;
use App\Repository\AutorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Autor o responsable de recursos bibliográficos (libros, artículos, multimedia). */
#[ORM\Entity(repositoryClass: AutorRepository::class)]
#[ORM\Index(name: 'idx_autor_apellidos', columns: ['apellidos', 'nombres'])]
#[ORM\UniqueConstraint(name: 'uniq_autor_orcid', columns: ['orcid'])]
#[UniqueEntity(fields: ['orcid'], message: 'autor.orcid_repetido')]
class Autor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, options: ['default' => ''])]
    #[Assert\Length(max: 120)]
    private string $nombres = '';

    /** Apellidos, o el nombre completo de un autor institucional (p. ej. "Organización Mundial de la Salud"). */
    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $apellidos = '';

    /** ORCID iD sin la dirección, p. ej. "0000-0002-1825-0097". */
    #[ORM\Column(length: 19, nullable: true)]
    private ?string $orcid = null;

    #[ORM\Column(length: 20, enumType: Sexo::class, options: ['default' => 'no_informado'])]
    private Sexo $sexo = Sexo::NoInformado;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Pais $pais = null;

    /** @var Collection<int, RecursoAutor> */
    #[ORM\OneToMany(targetEntity: RecursoAutor::class, mappedBy: 'autor')]
    private Collection $autorias;

    public function __construct()
    {
        $this->autorias = new ArrayCollection();
    }

    /**
     * Interpreta "Apellidos, Nombres" (forma habitual en bibliografías). Sin coma, el texto completo queda como
     * apellidos: así se conservan tal cual los autores institucionales.
     */
    public static function desdeTexto(string $texto): self
    {
        [$apellidos, $nombres] = self::separarNombre($texto);

        return (new self())->setApellidos($apellidos)->setNombres($nombres);
    }

    /** @return array{0: string, 1: string} [apellidos, nombres] */
    public static function separarNombre(string $texto): array
    {
        $partes = explode(',', $texto, 2);

        return [self::limpiar($partes[0]), self::limpiar($partes[1] ?? '')];
    }

    /** Clave para comparar nombres sin mayúsculas ni tildes, como la intercalación de MySQL ("PEREZ, ana" = "Pérez, Ana"). */
    public static function clave(string $apellidos, string $nombres): string
    {
        $texto = mb_strtolower(self::limpiar($apellidos).'|'.self::limpiar($nombres));
        $texto = \Normalizer::normalize($texto, \Normalizer::FORM_D) ?: $texto;

        return (string) preg_replace('/\p{Mn}+/u', '', $texto);
    }

    /** Normaliza un ORCID: quita la dirección https://orcid.org/, espacios y deja la X final en mayúscula. */
    public static function normalizarOrcid(?string $orcid): ?string
    {
        $orcid = strtoupper((string) preg_replace('~^\s*(https?://)?(www\.)?orcid\.org/~i', '', (string) $orcid));
        $digitos = (string) preg_replace('/[^0-9X]/', '', $orcid);
        if ('' === $digitos) {
            return null;
        }

        return 16 === strlen($digitos) ? implode('-', str_split($digitos, 4)) : trim($orcid);
    }

    /** Dígito verificador ISO 7064 11,2 del ORCID. */
    public static function orcidValido(string $orcid): bool
    {
        if (!preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', $orcid)) {
            return false;
        }
        $digitos = str_replace('-', '', $orcid);
        $total = 0;
        for ($i = 0; $i < 15; ++$i) {
            $total = ($total + (int) $digitos[$i]) * 2;
        }
        $resultado = (12 - $total % 11) % 11;

        return $digitos[15] === (10 === $resultado ? 'X' : (string) $resultado);
    }

    #[Assert\Callback]
    public function validarOrcid(ExecutionContextInterface $contexto): void
    {
        if (null !== $this->orcid && !self::orcidValido($this->orcid)) {
            $contexto->buildViolation('autor.orcid_no_valido')->atPath('orcid')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombres(): string
    {
        return $this->nombres;
    }

    public function setNombres(?string $nombres): static
    {
        $this->nombres = self::limpiar((string) $nombres);

        return $this;
    }

    public function getApellidos(): string
    {
        return $this->apellidos;
    }

    public function setApellidos(?string $apellidos): static
    {
        $this->apellidos = self::limpiar((string) $apellidos);

        return $this;
    }

    /** Forma bibliográfica: "Apellidos, Nombres". */
    public function getNombreCompleto(): string
    {
        return '' === $this->nombres ? $this->apellidos : $this->apellidos.', '.$this->nombres;
    }

    public function getOrcid(): ?string
    {
        return $this->orcid;
    }

    public function setOrcid(?string $orcid): static
    {
        $this->orcid = self::normalizarOrcid($orcid);

        return $this;
    }

    public function getEnlaceOrcid(): ?string
    {
        return null === $this->orcid ? null : 'https://orcid.org/'.$this->orcid;
    }

    public function getSexo(): Sexo
    {
        return $this->sexo;
    }

    public function setSexo(?Sexo $sexo): static
    {
        $this->sexo = $sexo ?? Sexo::NoInformado;

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

    /** Cantidad de recursos en que aparece. */
    public function getCantidadRecursos(): int
    {
        return $this->autorias->count();
    }

    public function __toString(): string
    {
        return $this->getNombreCompleto();
    }

    private static function limpiar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }
}
