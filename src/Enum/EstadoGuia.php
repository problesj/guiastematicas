<?php

declare(strict_types=1);

namespace App\Enum;

enum EstadoGuia: string
{
    case Borrador = 'borrador';
    case Publicada = 'publicada';

    public function etiqueta(): string
    {
        return 'estado_guia.'.$this->value;
    }
}
