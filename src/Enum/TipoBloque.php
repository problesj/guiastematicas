<?php

declare(strict_types=1);

namespace App\Enum;

enum TipoBloque: string
{
    case Texto = 'texto';
    case Recursos = 'recursos';
    case Perfil = 'perfil';
    case Video = 'video';
    case Busqueda = 'busqueda';
    case Enlaces = 'enlaces';
    case Libros = 'libros';
    case Diapositivas = 'diapositivas';

    public function etiqueta(): string
    {
        return 'tipo_bloque.'.$this->value;
    }

    public function ayuda(): string
    {
        return 'tipo_bloque.'.$this->value.'_ayuda';
    }
}
