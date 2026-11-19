<?php

declare(strict_types=1);

namespace App\Paginacion;

use Symfony\Component\HttpFoundation\Request;

/**
 * Recuerda en la sesión los filtros, la página y la cantidad por página de un listado del panel, para que al volver
 * (después de editar, eliminar o navegar a otra sección) se muestre igual. Se olvidan al cerrar la sesión o con
 * «Limpiar filtros» (?limpiar=1).
 */
final class FiltrosSesion
{
    private const string PREFIJO = 'filtros_panel.';

    /**
     * Guarda los filtros que trae la URL o, si no trae ninguno, devuelve los guardados para redirigir a ellos.
     *
     * @param list<string> $claves parámetros de la URL que forman parte del filtro
     *
     * @return array<string, string>|null parámetros a los que redirigir; null si se muestra la página tal cual
     */
    public function recordar(Request $request, string $listado, array $claves): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }
        $sesion = $request->getSession();
        $clave = self::PREFIJO.$listado;

        if ($request->query->has('limpiar')) {
            $sesion->remove($clave);

            return [];
        }

        $consulta = $request->query->all();
        $presentes = array_intersect_key($consulta, array_flip($claves));
        if ([] !== $presentes) {
            $valores = array_filter(
                array_map(fn (mixed $v) => \is_scalar($v) ? mb_substr(trim((string) $v), 0, 100) : '', $presentes),
                fn (string $v) => '' !== $v,
            );
            [] === $valores ? $sesion->remove($clave) : $sesion->set($clave, $valores);

            return null;
        }

        $guardados = $sesion->get($clave);

        return \is_array($guardados) && [] !== $guardados ? $guardados : null;
    }

    /** Hay filtros que acotan el listado (sin contar la página ni la cantidad por página). */
    public static function hayFiltros(Request $request, array $claves): bool
    {
        foreach (array_diff($claves, ['pagina', 'por_pagina']) as $clave) {
            if ('' !== trim($request->query->getString($clave))) {
                return true;
            }
        }

        return false;
    }
}
