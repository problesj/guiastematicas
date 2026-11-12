<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Valor de una integración definido desde el panel (proxy, catálogo, LDAP, Google...). Reemplaza a la variable de
 * entorno del mismo nombre; los secretos se guardan cifrados (ver App\Configuracion\Ajustes).
 */
#[ORM\Entity]
class Ajuste
{
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $actualizadoEn;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 60)]
        private string $clave,
        #[ORM\Column(type: 'text')]
        private string $valor,
        #[ORM\Column]
        private bool $cifrado = false,
    ) {
        $this->actualizadoEn = new \DateTimeImmutable();
    }

    public function getClave(): string
    {
        return $this->clave;
    }

    public function getValor(): string
    {
        return $this->valor;
    }

    public function isCifrado(): bool
    {
        return $this->cifrado;
    }

    public function cambiar(string $valor, bool $cifrado): void
    {
        $this->valor = $valor;
        $this->cifrado = $cifrado;
        $this->actualizadoEn = new \DateTimeImmutable();
    }

    public function getActualizadoEn(): \DateTimeImmutable
    {
        return $this->actualizadoEn;
    }
}
