<?php

declare(strict_types=1);

namespace App\Enum;

/** Quién puede ver una guía publicada. */
enum VisibilidadGuia: string
{
    /** Cualquier visitante, sin iniciar sesión. */
    case Publica = 'publica';
    /** Solo los lectores asignados, el responsable, los coeditores y los administradores, con sesión iniciada. */
    case Restringida = 'restringida';

    public function etiqueta(): string
    {
        return 'visibilidad.'.$this->value;
    }
}
