<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class MensajeConsulta
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'mensajes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Consulta $consulta;

    #[ORM\Column(type: Types::TEXT)]
    private string $texto;

    /** Quién respondió desde el panel; null si lo escribió la persona que consulta. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Usuario $autor;

    #[ORM\Column(options: ['default' => false])]
    private bool $dePersonal;

    #[ORM\Column]
    private \DateTimeImmutable $enviadoEn;

    /** Si el correo de aviso de este mensaje salió (false: falta configurar el correo o falló el envío). */
    #[ORM\Column(options: ['default' => false])]
    private bool $correoEnviado = false;

    public function __construct(Consulta $consulta, string $texto, ?Usuario $autor)
    {
        $this->consulta = $consulta;
        $this->texto = trim($texto);
        $this->autor = $autor;
        $this->dePersonal = null !== $autor;
        $this->enviadoEn = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConsulta(): Consulta
    {
        return $this->consulta;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function getAutor(): ?Usuario
    {
        return $this->autor;
    }

    public function isDePersonal(): bool
    {
        return $this->dePersonal;
    }

    public function getEnviadoEn(): \DateTimeImmutable
    {
        return $this->enviadoEn;
    }

    public function isCorreoEnviado(): bool
    {
        return $this->correoEnviado;
    }

    public function setCorreoEnviado(bool $enviado): static
    {
        $this->correoEnviado = $enviado;

        return $this;
    }
}
