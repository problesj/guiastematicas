<?php

declare(strict_types=1);

namespace App\Enum;

enum Sexo: string
{
    case Femenino = 'femenino';
    case Masculino = 'masculino';
    case Otro = 'otro';
    case NoInformado = 'no_informado';

    public function etiqueta(): string
    {
        return 'sexo.'.$this->value;
    }
}
