<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Repository\RecursoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Recurso electrónico de la lista A-Z (base de datos, plataforma de revistas o libros, repositorio, etc.).
 *
 * Se guarda la URL original; si requiereProxy está marcado, el enlace público se arma con el prefijo de
 * AccesUM configurado en PROXY_URL (ver App\Servicio\ProxyAccesum).
 */
#[ORM\Entity(repositoryClass: RecursoRepository::class)]
#[ORM\Index(name: 'idx_recurso_letra', columns: ['letra'])]
#[ORM\HasLifecycleCallbacks]
class Recurso
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $titulo = '';

    /** Primera letra del título sin tildes (A-Z) o "#" si empieza con número o símbolo. */
    #[ORM\Column(length: 1)]
    private string $letra = '#';

    /** Otros nombres o siglas por los que se busca el recurso, separados por punto y coma. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $nombresAlternativos = null;

    #[ORM\Column(length: 1000)]
    #[Assert\NotBlank]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 1000)]
    private string $url = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $requiereProxy = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 3000)]
    private ?string $descripcion = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 3000)]
    private ?string $descripcionEn = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $proveedor = null;

    #[ORM\Column(length: 20, enumType: TipoRecurso::class)]
    private TipoRecurso $tipo = TipoRecurso::BaseDatos;

    #[ORM\Column(length: 20, enumType: TipoAcceso::class)]
    private TipoAcceso $acceso = TipoAcceso::Suscripcion;

    /** Fin del periodo de prueba (solo para acceso "prueba"); después deja de mostrarse. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pruebaHasta = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $visible = true;

    #[ORM\ManyToMany(targetEntity: Materia::class)]
    #[ORM\JoinTable(name: 'recurso_materia')]
    private Collection $materias;

    #[ORM\Column(options: ['default' => 0])]
    private int $clics = 0;

    #[ORM\Column]
    private \DateTimeImmutable $creadoEn;

    #[ORM\Column]
    private \DateTimeImmutable $actualizadoEn;

    public function __construct()
    {
        $this->materias = new ArrayCollection();
        $this->creadoEn = new \DateTimeImmutable();
        $this->actualizadoEn = $this->creadoEn;
    }

    #[ORM\PreUpdate]
    public function marcarActualizado(): void
    {
        $this->actualizadoEn = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    /** Acepta null (campo vacío en un formulario); la validación NotBlank informa el error. */
    public function setTitulo(?string $titulo): static
    {
        $this->titulo = trim((string) $titulo);
        $this->letra = self::calcularLetra($this->titulo);

        return $this;
    }

    public static function calcularLetra(string $titulo): string
    {
        $inicial = strtoupper(substr((new AsciiSlugger())->slug($titulo)->toString(), 0, 1));

        return 1 === preg_match('/^[A-Z]$/', $inicial) ? $inicial : '#';
    }

    public function getLetra(): string
    {
        return $this->letra;
    }

    public function getNombresAlternativos(): ?string
    {
        return $this->nombresAlternativos;
    }

    public function setNombresAlternativos(?string $nombres): static
    {
        $this->nombresAlternativos = $nombres;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /** Acepta null (campo vacío en un formulario); la validación NotBlank informa el error. */
    public function setUrl(?string $url): static
    {
        $this->url = trim((string) $url);

        return $this;
    }

    public function isRequiereProxy(): bool
    {
        return $this->requiereProxy;
    }

    public function setRequiereProxy(bool $requiereProxy): static
    {
        $this->requiereProxy = $requiereProxy;

        return $this;
    }

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): static
    {
        $this->descripcion = $descripcion;

        return $this;
    }

    public function getDescripcionEn(): ?string
    {
        return $this->descripcionEn;
    }

    public function setDescripcionEn(?string $descripcionEn): static
    {
        $this->descripcionEn = $descripcionEn;

        return $this;
    }

    public function getDescripcionTraducida(string $idioma): ?string
    {
        return 'en' === $idioma && $this->descripcionEn ? $this->descripcionEn : $this->descripcion;
    }

    public function getProveedor(): ?string
    {
        return $this->proveedor;
    }

    public function setProveedor(?string $proveedor): static
    {
        $this->proveedor = $proveedor;

        return $this;
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

    public function getAcceso(): TipoAcceso
    {
        return $this->acceso;
    }

    public function setAcceso(TipoAcceso $acceso): static
    {
        $this->acceso = $acceso;

        return $this;
    }

    public function getPruebaHasta(): ?\DateTimeImmutable
    {
        return $this->pruebaHasta;
    }

    public function setPruebaHasta(?\DateTimeImmutable $pruebaHasta): static
    {
        $this->pruebaHasta = $pruebaHasta;

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    /** Visible en la lista pública: marcado como visible y, si es de prueba, aún vigente. */
    public function estaDisponible(?\DateTimeImmutable $hoy = null): bool
    {
        $hoy ??= new \DateTimeImmutable('today');

        return $this->visible
            && (TipoAcceso::Prueba !== $this->acceso || null === $this->pruebaHasta || $this->pruebaHasta >= $hoy);
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

    public function getClics(): int
    {
        return $this->clics;
    }

    public function getCreadoEn(): \DateTimeImmutable
    {
        return $this->creadoEn;
    }

    public function getActualizadoEn(): \DateTimeImmutable
    {
        return $this->actualizadoEn;
    }

    /** Recurso agregado en los últimos 60 días (se marca como "nuevo" en la lista). */
    public function esNuevo(?\DateTimeImmutable $hoy = null): bool
    {
        return $this->creadoEn > ($hoy ?? new \DateTimeImmutable())->modify('-60 days');
    }
}
