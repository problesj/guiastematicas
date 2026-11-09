<?php

declare(strict_types=1);

namespace App\Enum;

/** Cómo se accede al recurso. Los de suscripción normalmente pasan por el proxy AccesUM fuera del campus. */
enum TipoAcceso: string
{
    case Suscripcion = 'suscripcion';
    case Abierto = 'abierto';
    case Prueba = 'prueba';

    public function etiqueta(): string
    {
        return 'tipo_acceso.'.$this->value;
    }
}
