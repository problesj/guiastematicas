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
 * Recurso bibliográfico electrónico de la lista A-Z (base de datos, revista, libro, norma, repositorio, etc.).
 *
 * Los datos bibliográficos (autores, ISBN, ISSN, año...) dependen del tipo: ver TipoRecurso::campos().
 * Los que no corresponden al tipo se vacían al guardar (limpiarCamposNoAplicables).
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

    /** Autores o responsables, separados por punto y coma (libros, multimedia). */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $autores = null;

    /** Revista o publicación donde aparece un artículo. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $fuente = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $volumen = null;

    /** Número o fascículo de la revista. */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $fasciculo = null;

    /** Páginas, p. ej. "15-32" o "e1234". */
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $paginas = null;

    /** DOI sin el prefijo https://doi.org/, p. ej. "10.1016/j.cell.2020.01.001". */
    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Regex(pattern: '~^10\.\d{4,9}/\S+$~', message: 'recurso.doi_no_valido')]
    #[Assert\Length(max: 200)]
    private ?string $doi = null;

    /** Editorial o editor (libros, revistas). Distinto del proveedor o plataforma de acceso. */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $editorial = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Isbn(message: 'recurso.isbn_no_valido')]
    private ?string $isbn = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Isbn(message: 'recurso.isbn_no_valido')]
    private ?string $eisbn = null;

    /** Año de edición o publicación (libros, normas, multimedia). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 1450, max: 2100)]
    private ?int $anio = null;

    #[ORM\Column(length: 9, nullable: true)]
    #[Assert\Issn(message: 'recurso.issn_no_valido')]
    private ?string $issn = null;

    #[ORM\Column(length: 9, nullable: true)]
    #[Assert\Issn(message: 'recurso.issn_no_valido')]
    private ?string $eissn = null;

    /** Fecha desde la cual la UCN tiene acceso al contenido (revistas, bases de datos). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $disponibleDesde = null;

    /** Código de la norma, p. ej. "NCh 433" o "ISO 9001:2015". */
    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60)]
    private ?string $codigoNorma = null;

    /** Organismo que emite la norma, p. ej. INN, ISO, IEEE. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $organismo = null;

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

    public function getAutores(): ?string
    {
        return $this->autores;
    }

    public function setAutores(?string $autores): static
    {
        $this->autores = self::textoONulo($autores);

        return $this;
    }

    public function getFuente(): ?string
    {
        return $this->fuente;
    }

    public function setFuente(?string $fuente): static
    {
        $this->fuente = self::textoONulo($fuente);

        return $this;
    }

    public function getVolumen(): ?string
    {
        return $this->volumen;
    }

    public function setVolumen(?string $volumen): static
    {
        $this->volumen = self::textoONulo($volumen);

        return $this;
    }

    public function getFasciculo(): ?string
    {
        return $this->fasciculo;
    }

    public function setFasciculo(?string $fasciculo): static
    {
        $this->fasciculo = self::textoONulo($fasciculo);

        return $this;
    }

    public function getPaginas(): ?string
    {
        return $this->paginas;
    }

    public function setPaginas(?string $paginas): static
    {
        $paginas = self::textoONulo($paginas);
        // BibTeX usa "--" entre páginas.
        $this->paginas = null === $paginas ? null : (string) preg_replace('/\s*-{1,2}\s*|\s*–\s*/u', '-', $paginas);

        return $this;
    }

    public function getDoi(): ?string
    {
        return $this->doi;
    }

    /** Acepta el DOI solo o como enlace (https://doi.org/..., doi:...) y guarda solo el identificador. */
    public function setDoi(?string $doi): static
    {
        $doi = self::textoONulo($doi);
        $this->doi = null === $doi ? null : (string) preg_replace('~^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)~i', '', $doi);

        return $this;
    }

    public function getEnlaceDoi(): ?string
    {
        return null === $this->doi ? null : 'https://doi.org/'.$this->doi;
    }

    /** Si falta la URL y hay DOI, usa el enlace del DOI (habitual en artículos). */
    public function completarUrlDesdeDoi(): static
    {
        if ('' === $this->url && null !== $this->doi) {
            $this->url = (string) $this->getEnlaceDoi();
        }

        return $this;
    }

    public function getEditorial(): ?string
    {
        return $this->editorial;
    }

    public function setEditorial(?string $editorial): static
    {
        $this->editorial = self::textoONulo($editorial);

        return $this;
    }

    public function getIsbn(): ?string
    {
        return $this->isbn;
    }

    public function setIsbn(?string $isbn): static
    {
        $this->isbn = self::normalizarIsbn($isbn);

        return $this;
    }

    public function getEisbn(): ?string
    {
        return $this->eisbn;
    }

    public function setEisbn(?string $eisbn): static
    {
        $this->eisbn = self::normalizarIsbn($eisbn);

        return $this;
    }

    public function getAnio(): ?int
    {
        return $this->anio;
    }

    public function setAnio(?int $anio): static
    {
        $this->anio = $anio;

        return $this;
    }

    public function getIssn(): ?string
    {
        return $this->issn;
    }

    public function setIssn(?string $issn): static
    {
        $this->issn = self::normalizarIssn($issn);

        return $this;
    }

    public function getEissn(): ?string
    {
        return $this->eissn;
    }

    public function setEissn(?string $eissn): static
    {
        $this->eissn = self::normalizarIssn($eissn);

        return $this;
    }

    public function getDisponibleDesde(): ?\DateTimeImmutable
    {
        return $this->disponibleDesde;
    }

    public function setDisponibleDesde(?\DateTimeImmutable $disponibleDesde): static
    {
        $this->disponibleDesde = $disponibleDesde;

        return $this;
    }

    public function getCodigoNorma(): ?string
    {
        return $this->codigoNorma;
    }

    public function setCodigoNorma(?string $codigoNorma): static
    {
        $this->codigoNorma = self::textoONulo($codigoNorma);

        return $this;
    }

    public function getOrganismo(): ?string
    {
        return $this->organismo;
    }

    public function setOrganismo(?string $organismo): static
    {
        $this->organismo = self::textoONulo($organismo);

        return $this;
    }

    /**
     * Datos bibliográficos con valor, en el orden del tipo, listos para mostrar.
     *
     * @return array<string, string> clave de traducción (recurso.<clave>) => valor
     */
    public function getDatosBibliograficos(): array
    {
        $datos = [];
        foreach ($this->tipo->campos() as $campo) {
            $valor = $this->{$campo};
            if (null === $valor) {
                continue;
            }
            $clave = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $campo));
            $datos[$clave] = $valor instanceof \DateTimeImmutable ? $valor->format('d-m-Y') : (string) $valor;
        }

        return $datos;
    }

    /** Vacía los datos bibliográficos que no corresponden al tipo (p. ej. un ISBN que quedó al cambiar de Libro a Revista). */
    public function limpiarCamposNoAplicables(): static
    {
        foreach (TipoRecurso::CAMPOS_BIBLIOGRAFICOS as $campo) {
            if (!$this->tipo->usaCampo($campo)) {
                $this->{$campo} = null;
            }
        }

        return $this;
    }

    private static function textoONulo(?string $texto): ?string
    {
        $texto = null === $texto ? null : trim($texto);

        return '' === $texto ? null : $texto;
    }

    /** Quita espacios y deja la X final en mayúscula; conserva los guiones. */
    private static function normalizarIsbn(?string $isbn): ?string
    {
        $isbn = self::textoONulo($isbn);

        return null === $isbn ? null : strtoupper((string) preg_replace('/\s+/', '', $isbn));
    }

    /** Deja el ISSN como 1234-567X cuando trae 8 caracteres sin guion. */
    private static function normalizarIssn(?string $issn): ?string
    {
        $issn = self::textoONulo($issn);
        if (null === $issn) {
            return null;
        }
        $issn = strtoupper((string) preg_replace('/\s+/', '', $issn));

        return 1 === preg_match('/^\d{7}[\dX]$/', $issn) ? substr($issn, 0, 4).'-'.substr($issn, 4) : $issn;
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
