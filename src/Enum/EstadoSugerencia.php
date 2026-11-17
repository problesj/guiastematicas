<?php

declare(strict_types=1);

namespace App\Enum;

enum EstadoSugerencia: string
{
    case Pendiente = 'pendiente';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';

    public function etiqueta(): string
    {
        return 'estado_sugerencia.'.$this->value;
    }
}
