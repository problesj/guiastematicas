<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Roles del personal. La jerarquía (security.yaml) es ADMINISTRADOR > EDITOR > COLABORADOR:
 * - Administrador: gestiona usuarios, departamentos y materias, y todas las guías.
 * - Editor: crea y publica sus propias guías (fase 4).
 * - Colaborador: edita las guías que un editor le comparte (fase 4).
 * - Lector: no entra al panel; solo inicia sesión para ver las guías restringidas que tenga asignadas.
 */
enum Rol: string
{
    case Administrador = 'ROLE_ADMINISTRADOR';
    case Editor = 'ROLE_EDITOR';
    case Colaborador = 'ROLE_COLABORADOR';
    case Lector = 'ROLE_LECTOR';

    public function etiqueta(): string
    {
        return 'rol.'.strtolower($this->name);
    }

    /** Personal de la biblioteca (entra al panel y aparece en el directorio). */
    public function esPersonal(): bool
    {
        return self::Lector !== $this;
    }
}
