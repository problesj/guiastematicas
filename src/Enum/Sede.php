<?php

declare(strict_types=1);

namespace App\Enum;

enum Sede: string
{
    case Antofagasta = 'antofagasta';
    case Coquimbo = 'coquimbo';

    public function etiqueta(): string
    {
        return 'sede.'.$this->value;
    }
}
