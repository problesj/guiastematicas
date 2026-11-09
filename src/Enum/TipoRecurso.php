<?php

declare(strict_types=1);

namespace App\Enum;

enum TipoRecurso: string
{
    case BaseDatos = 'base_datos';
    case Revistas = 'revistas';
    case Libros = 'libros';
    case Repositorio = 'repositorio';
    case Normas = 'normas';
    case Multimedia = 'multimedia';
    case Herramienta = 'herramienta';
    case SitioWeb = 'sitio_web';

    public function etiqueta(): string
    {
        return 'tipo_recurso.'.$this->value;
    }
}
