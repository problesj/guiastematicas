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

    /** Campos bibliográficos propios de cada tipo (nombres de propiedades de Recurso). */
    public const array CAMPOS_BIBLIOGRAFICOS = ['autores', 'editorial', 'isbn', 'eisbn', 'anio', 'issn', 'eissn', 'disponibleDesde', 'codigoNorma', 'organismo'];

    public function etiqueta(): string
    {
        return 'tipo_recurso.'.$this->value;
    }

    /**
     * Datos bibliográficos que se piden y muestran para este tipo, en el orden en que se muestran.
     *
     * @return list<string>
     */
    public function campos(): array
    {
        return match ($this) {
            self::Libros => ['autores', 'editorial', 'anio', 'isbn', 'eisbn'],
            self::Revistas => ['editorial', 'issn', 'eissn', 'disponibleDesde'],
            self::BaseDatos => ['disponibleDesde'],
            self::Normas => ['codigoNorma', 'organismo', 'anio'],
            self::Multimedia => ['autores', 'anio'],
            self::Repositorio, self::Herramienta, self::SitioWeb => [],
        };
    }

    public function usaCampo(string $campo): bool
    {
        return \in_array($campo, $this->campos(), true);
    }

    /**
     * Valores de los tipos que usan el campo, para mostrarlo u ocultarlo en el formulario.
     *
     * @return list<string>
     */
    public static function tiposConCampo(string $campo): array
    {
        return array_values(array_map(
            fn (self $t) => $t->value,
            array_filter(self::cases(), fn (self $t) => $t->usaCampo($campo)),
        ));
    }
}
