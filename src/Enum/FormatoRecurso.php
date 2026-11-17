<?php

declare(strict_types=1);

namespace App\Enum;

/** Formato en que la biblioteca tiene un libro o una revista. Los demás tipos de recurso no lo usan. */
enum FormatoRecurso: string
{
    case Electronico = 'electronico';
    case Impreso = 'impreso';
    case Ambos = 'ambos';

    public function etiqueta(): string
    {
        return 'formato_recurso.'.$this->value;
    }

    /** Texto de la opción en los filtros (impreso y electrónico incluyen los que están en ambos formatos). */
    public function filtro(): string
    {
        return 'formato_recurso.filtro_'.$this->value;
    }

    /**
     * Formatos que cumplen el filtro: «impreso» incluye los que están en ambos formatos, igual que «electrónico».
     *
     * @return list<self>
     */
    public function coincidentes(): array
    {
        return self::Ambos === $this ? [self::Ambos] : [$this, self::Ambos];
    }

    /** Impreso + electrónico = ambos. */
    public static function combinar(bool $impreso, bool $electronico): ?self
    {
        return match (true) {
            $impreso && $electronico => self::Ambos,
            $impreso => self::Impreso,
            $electronico => self::Electronico,
            default => null,
        };
    }

    /**
     * Formato según las categorías de entrega de un registro de Primo VE: "Alma-P" es material físico; "Alma-E",
     * "Alma-D" y los recursos remotos son electrónicos.
     *
     * @param iterable<mixed> $categorias
     */
    public static function desdeCategoriasPrimo(iterable $categorias): ?self
    {
        $impreso = $electronico = false;
        foreach ($categorias as $categoria) {
            $categoria = strtolower(trim((string) (\is_scalar($categoria) ? $categoria : '')));
            if ('alma-p' === $categoria) {
                $impreso = true;
            } elseif (\in_array($categoria, ['alma-e', 'alma-d', 'remote search resource', 'online resource', 'sfx resource'], true)) {
                $electronico = true;
            }
        }

        return self::combinar($impreso, $electronico);
    }
}
