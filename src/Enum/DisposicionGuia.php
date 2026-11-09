<?php

declare(strict_types=1);

namespace App\Enum;

/** Cómo se muestran las pestañas de una guía. */
enum DisposicionGuia: string
{
    case Pestanas = 'pestanas';
    case MenuLateral = 'menu_lateral';

    public function etiqueta(): string
    {
        return 'disposicion_guia.'.$this->value;
    }
}
