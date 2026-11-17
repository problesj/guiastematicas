<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConsultaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Conversación iniciada desde la ficha pública de un bibliotecario. La persona la sigue con un enlace
 * privado (token) y el bibliotecario responde desde el panel; cada mensaje queda registrado.
 */
#[ORM\Entity(repositoryClass: ConsultaRepository::class)]
#[ORM\Index(name: 'idx_consulta_pendiente', columns: ['pendiente', 'cerrada'])]
class Consulta
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Usuario $bibliotecario;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $nombre = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $correo = '';

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $asunto = '';

    /** Enlace privado para que la persona vea la conversación y responda. */
    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column(length: 2, options: ['default' => 'es'])]
    private string $idioma = 'es';

    /** true si el último mensaje es de la persona (falta respuesta del bibliotecario). */
    #[ORM\Column(options: ['default' => true])]
    private bool $pendiente = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $cerrada = false;

    #[ORM\Column]
    private \DateTimeImmutable $creadaEn;

    #[ORM\Column]
    private \DateTimeImmutable $actualizadaEn;

    /** @var Collection<int, MensajeConsulta> */
    #[ORM\OneToMany(targetEntity: MensajeConsulta::class, mappedBy: 'consulta', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['enviadoEn' => 'ASC', 'id' => 'ASC'])]
    private Collection $mensajes;

    public function __construct(Usuario $bibliotecario)
    {
        $this->bibliotecario = $bibliotecario;
        $this->token = bin2hex(random_bytes(32));
        $this->mensajes = new ArrayCollection();
        $this->creadaEn = $this->actualizadaEn = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBibliotecario(): Usuario
    {
        return $this->bibliotecario;
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

    public function getAsunto(): string
    {
        return $this->asunto;
    }

    public function setAsunto(?string $asunto): static
    {
        $this->asunto = trim((string) $asunto);

        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
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

    public function isPendiente(): bool
    {
        return $this->pendiente;
    }

    public function isCerrada(): bool
    {
        return $this->cerrada;
    }

    public function setCerrada(bool $cerrada): static
    {
        $this->cerrada = $cerrada;

        return $this;
    }

    public function getCreadaEn(): \DateTimeImmutable
    {
        return $this->creadaEn;
    }

    public function getActualizadaEn(): \DateTimeImmutable
    {
        return $this->actualizadaEn;
    }

    /** @return Collection<int, MensajeConsulta> */
    public function getMensajes(): Collection
    {
        return $this->mensajes;
    }

    /** Mensaje de la persona (el primero o una respuesta desde su enlace). */
    public function escribePersona(string $texto): MensajeConsulta
    {
        $this->pendiente = true;
        $this->cerrada = false;

        return $this->agregar(new MensajeConsulta($this, $texto, null));
    }

    /** Respuesta del personal desde el panel. */
    public function responde(Usuario $autor, string $texto): MensajeConsulta
    {
        $this->pendiente = false;

        return $this->agregar(new MensajeConsulta($this, $texto, $autor));
    }

    private function agregar(MensajeConsulta $mensaje): MensajeConsulta
    {
        $this->mensajes->add($mensaje);
        $this->actualizadaEn = $mensaje->getEnviadoEn();

        return $mensaje;
    }
}
