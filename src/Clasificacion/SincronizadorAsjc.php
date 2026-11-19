<?php

declare(strict_types=1);

namespace App\Clasificacion;

use Doctrine\DBAL\Connection;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Crea las materias base, una por categoría ASJC. Una materia que ya existía con el mismo nombre (en español o en
 * inglés, sin tildes ni mayúsculas) pasa a ser la de esa categoría en vez de duplicarse. Se puede repetir sin efecto.
 * Trabaja con SQL simple para servir tanto en la migración como en el comando y los datos de ejemplo.
 */
final class SincronizadorAsjc
{
    /** @return array{creadas: int, unidas: int} */
    public static function sincronizar(Connection $conexion): array
    {
        $resultado = ['creadas' => 0, 'unidas' => 0];
        $filas = $conexion->fetchAllAssociative('SELECT id, nombre, nombre_en, slug, codigo_asjc FROM materia');
        $existentes = [];
        $porNombre = [];
        $slugs = [];
        foreach ($filas as $fila) {
            $slugs[$fila['slug']] = true;
            if (null !== $fila['codigo_asjc']) {
                $existentes[(int) $fila['codigo_asjc']] = true;
                continue;
            }
            foreach ([$fila['nombre'], $fila['nombre_en']] as $nombre) {
                if (null !== $nombre && '' !== $nombre) {
                    $porNombre[self::normalizar($nombre)] ??= $fila;
                }
            }
        }

        $slugger = new AsciiSlugger();
        foreach (Asjc::CATEGORIAS as $codigo => [$es, $en]) {
            if (isset($existentes[$codigo])) {
                continue;
            }
            $igual = $porNombre[self::normalizar($es)] ?? $porNombre[self::normalizar($en)] ?? null;
            if (null !== $igual && !isset($igual['unida'])) {
                $conexion->update('materia', [
                    'codigo_asjc' => $codigo,
                    'asjc' => json_encode([$codigo]),
                    'nombre_en' => $igual['nombre_en'] ?: $en,
                ], ['id' => $igual['id']]);
                foreach ($porNombre as $clave => $fila) {
                    if ($fila['id'] === $igual['id']) {
                        $porNombre[$clave]['unida'] = true;
                    }
                }
                ++$resultado['unidas'];
                continue;
            }
            $base = $slugger->slug($es)->lower()->toString();
            $slug = isset($slugs[$base]) ? $base.'-'.$codigo : $base;
            $slugs[$slug] = true;
            $conexion->insert('materia', [
                'nombre' => $es,
                'nombre_en' => $en,
                'slug' => $slug,
                'activa' => 1,
                'asjc' => json_encode([$codigo]),
                'codigo_asjc' => $codigo,
            ]);
            ++$resultado['creadas'];
        }

        return $resultado;
    }

    private static function normalizar(string $texto): string
    {
        $sinTildes = (string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($texto, \Normalizer::FORM_D));

        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $sinTildes)));
    }
}
