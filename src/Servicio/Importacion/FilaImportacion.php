<?php

declare(strict_types=1);

namespace App\Servicio\Importacion;

use App\Entity\Recurso;

/** Resultado de interpretar una fila del Excel, para la vista previa y para aplicar la importación. */
final class FilaImportacion
{
    /** @var list<string> */
    public array $errores = [];

    /** @var list<string> */
    public array $avisos = [];

    /** @var list<string> nombres de materias tal como vienen en el archivo */
    public array $materias = [];

    public ?Recurso $existente = null;

    /**
     * @param array<string, mixed> $datos valores normalizados por campo (titulo, url, requiere_proxy, ...)
     */
    public function __construct(
        public readonly int $numero,
        public array $datos,
    ) {
    }

    public function esValida(): bool
    {
        return [] === $this->errores;
    }

    public function accion(): string
    {
        return match (true) {
            !$this->esValida() => 'error',
            null !== $this->existente => 'actualizar',
            default => 'crear',
        };
    }
}
