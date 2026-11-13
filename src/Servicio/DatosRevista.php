<?php

declare(strict_types=1);

namespace App\Servicio;

/**
 * Completa una revista del bloque "Revistas" con la búsqueda de revistas del catálogo (Primo VE) por ISSN: título,
 * editorial, el otro ISSN (impreso o electrónico), el registro del catálogo y el MMS id.
 */
final class DatosRevista
{
    public function __construct(private readonly Catalogo $catalogo)
    {
    }

    /** @return array{titulo: string, issn: ?string, eissn: ?string, editorial: ?string, registro: string, mms: ?string}|null */
    public function buscar(?string $issn): ?array
    {
        return null === Catalogo::issn($issn) ? null : $this->catalogo->revistaPorIssn((string) $issn);
    }

    /**
     * Completa los campos vacíos. El registro y el MMS id se recalculan siempre desde el ISSN (si no hay ISSN se
     * conservan los que trajo el buscador, que identifican la revista).
     *
     * @param array<string, mixed> $revista
     *
     * @return array<string, mixed>
     */
    public function completar(array $revista): array
    {
        foreach (['issn', 'eissn'] as $campo) {
            $revista[$campo] = Catalogo::issn(\is_string($revista[$campo] ?? null) ? $revista[$campo] : null) ?? ($revista[$campo] ?? null);
        }
        $issn = \is_string($revista['issn'] ?? null) && null !== Catalogo::issn($revista['issn']) ? $revista['issn']
            : (\is_string($revista['eissn'] ?? null) && null !== Catalogo::issn($revista['eissn']) ? $revista['eissn'] : null);
        if (null === $issn) {
            return $revista;
        }
        unset($revista['registro'], $revista['mms']);
        $datos = $this->catalogo->revistaPorIssn($issn);
        if (null === $datos) {
            return $revista;
        }
        foreach (['titulo', 'editorial', 'issn', 'eissn'] as $campo) {
            if (null === ($revista[$campo] ?? null) || '' === $revista[$campo]) {
                $revista[$campo] = $datos[$campo];
            }
        }
        if (($revista['eissn'] ?? null) === $revista['issn']) {
            $revista['eissn'] = $datos['issn'] === $revista['issn'] ? $datos['eissn'] : $datos['issn'];
        }
        $revista['registro'] = $datos['registro'];
        $revista['mms'] = $datos['mms'];

        return $revista;
    }
}
