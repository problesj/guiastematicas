<?php

declare(strict_types=1);

namespace App\Servicio;

/**
 * Completa un libro del bloque "Libros" con lo que se sabe de él por ISBN (catálogo de la biblioteca, Primo VE) y por
 * DOI (doi.org). El catálogo tiene prioridad porque además da el registro y los ejemplares de la biblioteca.
 */
final class DatosLibro
{
    private const CAMPOS = ['titulo', 'autor', 'editorial', 'anio'];

    public function __construct(
        private readonly Catalogo $catalogo,
        private readonly MetadatosDoi $doi,
    ) {
    }

    /**
     * Datos encontrados para un ISBN y/o DOI, sin tocar lo que escribió el editor.
     *
     * @return array{titulo: ?string, autor: ?string, editorial: ?string, anio: ?string, isbn: ?string, registro: ?string, mms: ?string, origen: list<string>}|null
     */
    public function buscar(?string $isbn, ?string $doi): ?array
    {
        $isbn = self::isbn($isbn);
        $porDoi = null !== $doi && '' !== trim($doi) ? $this->doi->datos($doi) : null;
        if (null === $isbn && self::isbnValido($porDoi['isbn'] ?? null)) {
            $isbn = $porDoi['isbn']; // un libro con DOI suele traer su ISBN: así se busca también en el catálogo
        }
        $enCatalogo = null !== $isbn ? $this->catalogo->libroPorIsbn($isbn) : null;
        if (null === $porDoi && null === $enCatalogo) {
            return null;
        }

        $datos = ['isbn' => $isbn, 'registro' => $enCatalogo['registro'] ?? null, 'mms' => $enCatalogo['mms'] ?? null, 'origen' => []];
        foreach (self::CAMPOS as $campo) {
            $datos[$campo] = ($enCatalogo[$campo] ?? null) ?: ($porDoi[$campo] ?? null);
        }
        if (null !== $enCatalogo) {
            $datos['origen'][] = 'catalogo';
        }
        if (null !== $porDoi) {
            $datos['origen'][] = 'doi';
        }

        return $datos;
    }

    /**
     * Completa los campos vacíos de un libro. El registro del catálogo y el MMS id se recalculan siempre desde el
     * ISBN, para que reordenar o cambiar un ISBN no deje datos de otro libro.
     *
     * @param array<string, mixed> $libro
     *
     * @return array<string, mixed>
     */
    public function completar(array $libro): array
    {
        $libro['isbn'] = self::isbn((string) ($libro['isbn'] ?? '')) ?? ($libro['isbn'] ?? null);
        $libro['doi'] = MetadatosDoi::normalizar((string) ($libro['doi'] ?? '')) ?? ($libro['doi'] ?? null);
        unset($libro['registro'], $libro['mms']);

        $datos = $this->buscar(\is_string($libro['isbn']) ? $libro['isbn'] : null, \is_string($libro['doi']) ? $libro['doi'] : null);
        if (null === $datos) {
            return $libro;
        }
        foreach ([...self::CAMPOS, 'isbn'] as $campo) {
            if (null === ($libro[$campo] ?? null) || '' === $libro[$campo]) {
                $libro[$campo] = $datos[$campo];
            }
        }
        $libro['registro'] = $datos['registro'];
        $libro['mms'] = $datos['mms'];

        return $libro;
    }

    /** ISBN sin guiones ni espacios, si tiene el largo de un ISBN (10 o 13); la validación completa la hace el formulario. */
    private static function isbn(?string $texto): ?string
    {
        $isbn = preg_replace('/[^0-9X]/', '', strtoupper((string) $texto)) ?? '';

        return \in_array(\strlen($isbn), [10, 13], true) ? $isbn : null;
    }

    private static function isbnValido(?string $isbn): bool
    {
        if (null === $isbn || 1 !== preg_match('/^(\d{9}[\dX]|\d{13})$/', $isbn)) {
            return false;
        }
        $suma = 0;
        if (10 === \strlen($isbn)) {
            foreach (str_split($isbn) as $i => $c) {
                $suma += ('X' === $c ? 10 : (int) $c) * (10 - $i);
            }

            return 0 === $suma % 11;
        }
        foreach (str_split($isbn) as $i => $c) {
            $suma += (int) $c * (0 === $i % 2 ? 1 : 3);
        }

        return 0 === $suma % 10;
    }
}
