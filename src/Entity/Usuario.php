<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Rol;
use App\Enum\Sede;
use App\Repository\UsuarioRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Persona del equipo de bibliotecas que puede iniciar sesión y, si se desea, aparecer en el directorio público.
 *
 * Puede entrar con tres métodos, no excluyentes:
 * - contraseña propia (campo password),
 * - LDAP UCN (campo ldapUsuario, el uid o sAMAccountName),
 * - Google (correo coincidente; googleId se guarda en el primer ingreso).
 */
#[ORM\Entity(repositoryClass: UsuarioRepository::class)]
#[ORM\Table(name: 'usuario')]
#[UniqueEntity(fields: ['email'], message: 'usuario.email_repetido')]
#[UniqueEntity(fields: ['ldapUsuario'], message: 'usuario.ldap_repetido', ignoreNull: true)]
class Usuario implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $nombre = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $apellidos = '';

    /** Identificador para la URL pública del perfil, p. ej. "ana-perez". */
    #[ORM\Column(length: 120, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 30, enumType: Rol::class)]
    private Rol $rol = Rol::Colaborador;

    /** Hash de la contraseña propia; null si la persona solo entra con LDAP o Google. */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 100, unique: true, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9._-]+$/', message: 'usuario.ldap_formato')]
    private ?string $ldapUsuario = null;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $googleId = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $activo = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $visibleEnDirectorio = true;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $cargo = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $cargoEn = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $telefono = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $ubicacion = null;

    #[ORM\Column(length: 20, nullable: true, enumType: Sede::class)]
    private ?Sede $sede = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 4000)]
    private ?string $biografia = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 4000)]
    private ?string $biografiaEn = null;

    /** Nombre del archivo de la foto dentro de public/uploads/personal. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $foto = null;

    #[ORM\ManyToOne(inversedBy: 'integrantes')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Departamento $departamento = null;

    /** Materias en las que la persona es especialista (aparece como contacto en las guías de esa materia). */
    #[ORM\ManyToMany(targetEntity: Materia::class, inversedBy: 'especialistas')]
    #[ORM\JoinTable(name: 'usuario_materia')]
    #[ORM\OrderBy(['nombre' => 'ASC'])]
    private Collection $materias;

    #[ORM\Column]
    private \DateTimeImmutable $creadoEn;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $ultimoAcceso = null;

    public function __construct()
    {
        $this->materias = new ArrayCollection();
        $this->creadoEn = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /** Acepta null (campo vacío en un formulario); la validación NotBlank informa el error. */
    public function setEmail(?string $email): static
    {
        $this->email = mb_strtolower(trim((string) $email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
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

    public function getApellidos(): string
    {
        return $this->apellidos;
    }

    /** Acepta null (campo vacío en un formulario); la validación NotBlank informa el error. */
    public function setApellidos(?string $apellidos): static
    {
        $this->apellidos = trim((string) $apellidos);

        return $this;
    }

    public function getNombreCompleto(): string
    {
        return trim($this->nombre.' '.$this->apellidos);
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /** Acepta null (campo vacío en un formulario); la validación NotBlank informa el error. */
    public function setSlug(?string $slug): static
    {
        $this->slug = (string) $slug;

        return $this;
    }

    public function getRol(): Rol
    {
        return $this->rol;
    }

    public function setRol(Rol $rol): static
    {
        $this->rol = $rol;

        return $this;
    }

    public function getRoles(): array
    {
        return [$this->rol->value, 'ROLE_USER'];
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function tieneContrasenaPropia(): bool
    {
        return null !== $this->password;
    }

    public function getLdapUsuario(): ?string
    {
        return $this->ldapUsuario;
    }

    public function setLdapUsuario(?string $ldapUsuario): static
    {
        $ldapUsuario = null === $ldapUsuario ? null : trim($ldapUsuario);
        $this->ldapUsuario = '' === $ldapUsuario ? null : $ldapUsuario;

        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;

        return $this;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): static
    {
        $this->activo = $activo;

        return $this;
    }

    public function isVisibleEnDirectorio(): bool
    {
        return $this->visibleEnDirectorio;
    }

    public function setVisibleEnDirectorio(bool $visible): static
    {
        $this->visibleEnDirectorio = $visible;

        return $this;
    }

    public function getCargo(): ?string
    {
        return $this->cargo;
    }

    public function setCargo(?string $cargo): static
    {
        $this->cargo = $cargo;

        return $this;
    }

    public function getCargoEn(): ?string
    {
        return $this->cargoEn;
    }

    public function setCargoEn(?string $cargoEn): static
    {
        $this->cargoEn = $cargoEn;

        return $this;
    }

    /** Cargo en el idioma pedido, con el español como respaldo. */
    public function getCargoTraducido(string $idioma): ?string
    {
        return 'en' === $idioma && $this->cargoEn ? $this->cargoEn : $this->cargo;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    public function setTelefono(?string $telefono): static
    {
        $this->telefono = $telefono;

        return $this;
    }

    public function getUbicacion(): ?string
    {
        return $this->ubicacion;
    }

    public function setUbicacion(?string $ubicacion): static
    {
        $this->ubicacion = $ubicacion;

        return $this;
    }

    public function getSede(): ?Sede
    {
        return $this->sede;
    }

    public function setSede(?Sede $sede): static
    {
        $this->sede = $sede;

        return $this;
    }

    public function getBiografia(): ?string
    {
        return $this->biografia;
    }

    public function setBiografia(?string $biografia): static
    {
        $this->biografia = $biografia;

        return $this;
    }

    public function getBiografiaEn(): ?string
    {
        return $this->biografiaEn;
    }

    public function setBiografiaEn(?string $biografiaEn): static
    {
        $this->biografiaEn = $biografiaEn;

        return $this;
    }

    public function getBiografiaTraducida(string $idioma): ?string
    {
        return 'en' === $idioma && $this->biografiaEn ? $this->biografiaEn : $this->biografia;
    }

    public function getFoto(): ?string
    {
        return $this->foto;
    }

    public function setFoto(?string $foto): static
    {
        $this->foto = $foto;

        return $this;
    }

    public function getIniciales(): string
    {
        return mb_strtoupper(mb_substr($this->nombre, 0, 1).mb_substr($this->apellidos, 0, 1));
    }

    public function getDepartamento(): ?Departamento
    {
        return $this->departamento;
    }

    public function setDepartamento(?Departamento $departamento): static
    {
        $this->departamento = $departamento;

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

    public function getCreadoEn(): \DateTimeImmutable
    {
        return $this->creadoEn;
    }

    public function getUltimoAcceso(): ?\DateTimeImmutable
    {
        return $this->ultimoAcceso;
    }

    public function registrarAcceso(): static
    {
        $this->ultimoAcceso = new \DateTimeImmutable();

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * Evita guardar el hash completo en la sesión; Symfony 7.3+ lo compara con un resumen.
     */
    public function __serialize(): array
    {
        $datos = (array) $this;
        $datos["\0".self::class."\0password"] = null === $this->password ? null : hash('crc32c', $this->password);

        return $datos;
    }
}
