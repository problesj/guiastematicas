<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EstadoSugerencia;
use App\Enum\TipoRecurso;
use App\Repository\SugerenciaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Recurso bibliográfico propuesto desde el sitio público. Lo aprueba o rechaza quien edita
 * una guía de alguna de sus materias (o un administrador); al aprobarlo se crea el Recurso.
 */
#[ORM\Entity(repositoryClass: SugerenciaRepository::class)]
#[ORM\Index(name: 'idx_sugerencia_estado', columns: ['estado'])]
class Sugerencia
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: TipoRecurso::class)]
    private TipoRecurso $tipo = TipoRecurso::BaseDatos;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $titulo = '';

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Assert\Url(requireTld: true)]
    private ?string $url = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $autores = null;

    /** ISBN o ISSN tal como lo escribió la persona. */
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    #[Assert\Regex(pattern: '/^[0-9Xx\- ]+$/', message: 'sugerencia.identificador_no_valido')]
    private ?string $identificador = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $descripcion = null;

    /** Por qué lo sugiere (curso, investigación...). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $motivo = null;

    /** @var Collection<int, Materia> */
    #[ORM\ManyToMany(targetEntity: Materia::class)]
    #[ORM\OrderBy(['nombre' => 'ASC'])]
    #[Assert\Count(min: 1, minMessage: 'sugerencia.materia_obligatoria')]
    private Collection $materias;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $nombre = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $correo = '';

    /** Idioma en que se envió (para responder en el mismo). */
    #[ORM\Column(length: 2, options: ['default' => 'es'])]
    private string $idioma = 'es';

    #[ORM\Column(length: 20, enumType: EstadoSugerencia::class, options: ['default' => 'pendiente'])]
    private EstadoSugerencia $estado = EstadoSugerencia::Pendiente;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motivoRechazo = null;

    #[ORM\Column]
    private \DateTimeImmutable $creadaEn;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decididaEn = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Usuario $decididaPor = null;

    /** Recurso creado al aprobarla. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Recurso $recurso = null;

    public function __construct()
    {
        $this->materias = new ArrayCollection();
        $this->creadaEn = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTipo(): TipoRecurso
    {
        return $this->tipo;
    }

    public function setTipo(TipoRecurso $tipo): static
    {
        $this->tipo = $tipo;

        return $this;
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

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = self::textoONulo($url);

        return $this;
    }

    public function getAutores(): ?string
    {
        return $this->autores;
    }

    public function setAutores(?string $autores): static
    {
        $this->autores = self::textoONulo($autores);

        return $this;
    }

    public function getIdentificador(): ?string
    {
        return $this->identificador;
    }

    public function setIdentificador(?string $identificador): static
    {
        $this->identificador = self::textoONulo($identificador);

        return $this;
    }

    /** true si el identificador tiene forma de ISSN (8 caracteres sin guiones). */
    public function identificadorEsIssn(): bool
    {
        return null !== $this->identificador && 1 === preg_match('/^\d{7}[\dX]$/', strtoupper(str_replace(['-', ' '], '', $this->identificador)));
    }

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): static
    {
        $this->descripcion = self::textoONulo($descripcion);

        return $this;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function setMotivo(?string $motivo): static
    {
        $this->motivo = self::textoONulo($motivo);

        return $this;
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

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setNombre(?string $nombre): static
    {
        $this->nombre = trim((string) $nombre);

        return $this;
    }

    public function getCorreo(): string
    {
        return $this->correo;
    }

    public function setCorreo(?string $correo): static
    {
        $this->correo = mb_strtolower(trim((string) $correo));

        return $this;
    }

    public function getIdioma(): string
    {
        return $this->idioma;
    }

    public function setIdioma(string $idioma): static
    {
        $this->idioma = 'en' === $idioma ? 'en' : 'es';

        return $this;
    }

    public function getEstado(): EstadoSugerencia
    {
        return $this->estado;
    }

    public function isPendiente(): bool
    {
        return EstadoSugerencia::Pendiente === $this->estado;
    }

    public function getMotivoRechazo(): ?string
    {
        return $this->motivoRechazo;
    }

    public function getCreadaEn(): \DateTimeImmutable
    {
        return $this->creadaEn;
    }

    public function getDecididaEn(): ?\DateTimeImmutable
    {
        return $this->decididaEn;
    }

    public function getDecididaPor(): ?Usuario
    {
        return $this->decididaPor;
    }

    public function getRecurso(): ?Recurso
    {
        return $this->recurso;
    }

    public function aprobar(Usuario $usuario, Recurso $recurso): void
    {
        $this->decidir(EstadoSugerencia::Aprobada, $usuario);
        $this->recurso = $recurso;
    }

    public function rechazar(Usuario $usuario, ?string $motivo): void
    {
        $this->decidir(EstadoSugerencia::Rechazada, $usuario);
        $this->motivoRechazo = self::textoONulo($motivo);
    }

    /** Recurso nuevo con los datos sugeridos, para que quien aprueba lo revise y complete. */
    public function crearRecurso(): Recurso
    {
        $recurso = (new Recurso())
            ->setTipo($this->tipo)
            ->setTitulo($this->titulo)
            ->setUrl($this->url ?? '')
            ->setDescripcion($this->descripcion);
        foreach ($this->materias as $materia) {
            $recurso->addMateria($materia);
        }
        if (null !== $this->identificador) {
            $this->identificadorEsIssn() ? $recurso->setIssn($this->identificador) : $recurso->setIsbn($this->identificador);
        }

        return $recurso;
    }

    private function decidir(EstadoSugerencia $estado, Usuario $usuario): void
    {
        if (!$this->isPendiente()) {
            throw new \LogicException('La sugerencia ya fue revisada.');
        }
        $this->estado = $estado;
        $this->decididaPor = $usuario;
        $this->decididaEn = new \DateTimeImmutable();
    }

    private static function textoONulo(?string $texto): ?string
    {
        $texto = null === $texto ? '' : trim($texto);

        return '' === $texto ? null : $texto;
    }
}
