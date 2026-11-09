<?php

declare(strict_types=1);

namespace App\Servicio\Importacion;

/**
 * Lee archivos bibliográficos BibTeX (.bib) y RIS (.ris) y devuelve cada registro con los mismos campos que usa
 * la importación desde Excel (titulo, url, tipo, autores, fuente, anio, volumen, fasciculo, paginas, doi, ...).
 *
 * Ambos formatos los exportan Zotero, Mendeley, EndNote, Google Académico, Scopus, Web of Science, etc.
 */
final class LectorBibliografia
{
    public const array EXTENSIONES = ['bib', 'bibtex', 'ris'];

    /**
     * @return list<array<string, string>>
     *
     * @throws ArchivoNoValidoException si el archivo no se puede leer
     */
    public function leer(string $ruta): array
    {
        $texto = @file_get_contents($ruta);
        if (false === $texto) {
            throw new ArchivoNoValidoException('importacion.archivo_ilegible');
        }
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        $texto = (string) preg_replace('/^\xEF\xBB\xBF/', '', $texto);

        return 'ris' === strtolower(pathinfo($ruta, \PATHINFO_EXTENSION)) || 1 === preg_match('/^\s*TY  - /m', $texto)
            ? $this->leerRis($texto)
            : $this->leerBibtex($texto);
    }

    /** @return list<array<string, string>> */
    public function leerBibtex(string $texto): array
    {
        $registros = [];
        $macros = []; // @string{abreviatura = "texto"}
        $largo = \strlen($texto);
        $pos = 0;
        while (false !== ($arroba = strpos($texto, '@', $pos))) {
            if (1 !== preg_match('/\G@\s*(\w+)\s*([{(])/A', $texto, $m, 0, $arroba)) {
                $pos = $arroba + 1;
                continue;
            }
            $tipo = strtolower($m[1]);
            $inicio = $arroba + \strlen($m[0]);
            $cierre = '{' === $m[2] ? '}' : ')';
            $fin = $this->buscarCierre($texto, $inicio, $m[2], $cierre);
            $pos = $fin + 1;
            $cuerpo = substr($texto, $inicio, $fin - $inicio);
            if ('string' === $tipo) {
                $macros += $this->camposBibtex(','.$cuerpo, $macros);
                continue;
            }
            if (\in_array($tipo, ['comment', 'preamble'], true)) {
                continue;
            }

            $campos = $this->camposBibtex($cuerpo, $macros);
            if ([] !== $campos) {
                $registros[] = $this->desdeBibtex($tipo, $campos);
            }
            if ($pos >= $largo) {
                break;
            }
        }

        return $registros;
    }

    /** @return list<array<string, string>> */
    public function leerRis(string $texto): array
    {
        $registros = [];
        $actual = null;
        $ultima = null;
        foreach (preg_split('/\R/', $texto) ?: [] as $linea) {
            if (1 === preg_match('/^([A-Z][A-Z0-9])  -\s?(.*)$/', $linea, $m)) {
                [$etiqueta, $valor] = [$m[1], trim($m[2])];
                if ('TY' === $etiqueta) {
                    $actual = ['TY' => [$valor]];
                } elseif ('ER' === $etiqueta) {
                    if (null !== $actual) {
                        $registros[] = $this->desdeRis($actual);
                    }
                    $actual = null;
                } elseif (null !== $actual && '' !== $valor) {
                    $actual[$etiqueta][] = $valor;
                }
                $ultima = $etiqueta;
            } elseif (null !== $actual && null !== $ultima && '' !== trim($linea) && isset($actual[$ultima])) {
                // Línea de continuación (resúmenes largos).
                $i = \count($actual[$ultima]) - 1;
                $actual[$ultima][$i] .= ' '.trim($linea);
            }
        }
        if (null !== $actual) {
            $registros[] = $this->desdeRis($actual);
        }

        return $registros;
    }

    /** Posición del delimitador que cierra el registro, respetando llaves anidadas y comillas. */
    private function buscarCierre(string $texto, int $desde, string $apertura, string $cierre): int
    {
        $nivel = 1;
        $largo = \strlen($texto);
        for ($i = $desde; $i < $largo; ++$i) {
            $c = $texto[$i];
            if ('\\' === $c) {
                ++$i;
            } elseif ('{' === $c || ($apertura === $c && '(' === $c)) {
                ++$nivel;
            } elseif ('}' === $c || ($cierre === $c && ')' === $c)) {
                if (0 === --$nivel) {
                    return $i;
                }
            }
        }

        return $largo;
    }

    /**
     * Separa "clave, campo = {valor}, campo = "valor", campo = 2020" en campo => valor.
     *
     * @param array<string, string> $macros abreviaturas definidas con @string
     *
     * @return array<string, string>
     */
    private function camposBibtex(string $cuerpo, array $macros = []): array
    {
        // Primero va la clave de cita, hasta la primera coma.
        $coma = strpos($cuerpo, ',');
        if (false === $coma) {
            return [];
        }
        $campos = [];
        $largo = \strlen($cuerpo);
        $i = $coma + 1;
        while ($i < $largo) {
            if (1 !== preg_match('/\G\s*([\w:.-]+)\s*=\s*/A', $cuerpo, $m, 0, $i)) {
                ++$i;
                continue;
            }
            $nombre = strtolower($m[1]);
            $i += \strlen($m[0]);
            $valor = '';
            // Un valor puede ser una concatenación: {a} # "b" # variable.
            do {
                $i += \strlen($cuerpo) - $i - \strlen(ltrim(substr($cuerpo, $i)));
                $c = $cuerpo[$i] ?? '';
                if ('{' === $c) {
                    $fin = $this->buscarCierre($cuerpo, $i + 1, '{', '}');
                    $valor .= substr($cuerpo, $i + 1, $fin - $i - 1);
                    $i = $fin + 1;
                } elseif ('"' === $c) {
                    $fin = $i + 1;
                    $nivel = 0;
                    while ($fin < $largo && ('"' !== $cuerpo[$fin] || $nivel > 0)) {
                        $nivel += '{' === $cuerpo[$fin] ? 1 : ('}' === $cuerpo[$fin] ? -1 : 0);
                        ++$fin;
                    }
                    $valor .= substr($cuerpo, $i + 1, $fin - $i - 1);
                    $i = $fin + 1;
                } elseif (1 === preg_match('/\G[\w.-]+/A', $cuerpo, $m2, 0, $i)) {
                    $valor .= $macros[strtolower($m2[0])] ?? $m2[0];
                    $i += \strlen($m2[0]);
                }
                $siguiente = 1 === preg_match('/\G\s*#\s*/A', $cuerpo, $m3, 0, $i);
                if ($siguiente) {
                    $i += \strlen($m3[0]);
                }
            } while ($siguiente);

            $campos[$nombre] = self::limpiarLatex($valor);
            $i = false === ($coma = strpos($cuerpo, ',', $i)) ? $largo : $coma + 1;
        }

        return $campos;
    }

    /**
     * @param array<string, string> $c
     *
     * @return array<string, string>
     */
    private function desdeBibtex(string $tipo, array $c): array
    {
        $url = $c['url'] ?? null;
        $tipoRecurso = match ($tipo) {
            'article' => 'articulo',
            'book', 'inbook', 'incollection', 'booklet', 'collection', 'mvbook', 'reference' => 'libros',
            'standard' => 'normas',
            'phdthesis', 'mastersthesis', 'thesis', 'techreport', 'report' => 'repositorio',
            'periodical' => 'revistas',
            'video', 'movie', 'audio', 'music' => 'multimedia',
            default => null !== $url && self::esVideo($url) ? 'multimedia' : 'sitio_web',
        };

        $registro = [
            'titulo' => $c['title'] ?? $c['booktitle'] ?? '',
            'tipo' => $tipoRecurso,
            'url' => $url,
            'autores' => self::autores($c['author'] ?? $c['editor'] ?? null, ' and '),
            'fuente' => $c['journal'] ?? $c['journaltitle'] ?? $c['booktitle'] ?? null,
            'anio' => $c['year'] ?? (isset($c['date']) ? substr($c['date'], 0, 4) : null),
            'volumen' => $c['volume'] ?? null,
            'fasciculo' => $c['number'] ?? $c['issue'] ?? null,
            'paginas' => $c['pages'] ?? null,
            'doi' => $c['doi'] ?? null,
            'editorial' => $c['publisher'] ?? $c['institution'] ?? $c['school'] ?? $c['organization'] ?? null,
            'isbn' => $c['isbn'] ?? null,
            'issn' => $c['issn'] ?? null,
            'descripcion' => $c['abstract'] ?? null,
        ];
        if ('normas' === $tipoRecurso) {
            $registro['codigo_norma'] = $c['number'] ?? null;
            $registro['organismo'] = $c['organization'] ?? $c['institution'] ?? $c['publisher'] ?? null;
        }

        return self::sinVacios($registro);
    }

    /**
     * @param array<string, list<string>> $r
     *
     * @return array<string, string>
     */
    private function desdeRis(array $r): array
    {
        $primero = fn (string ...$etiquetas): ?string => array_reduce($etiquetas, fn (?string $v, string $e) => $v ?? ($r[$e][0] ?? null));
        $url = $primero('UR', 'L2');
        $tipoRis = strtoupper($r['TY'][0] ?? '');
        $tipo = match ($tipoRis) {
            'JOUR', 'JFULL', 'MGZN', 'NEWS', 'EJOUR', 'ABST', 'INPR' => 'articulo',
            'BOOK', 'EBOOK', 'CHAP', 'ECHAP', 'EDBOOK', 'CTLG', 'ENCYC', 'DICT' => 'libros',
            'STAND' => 'normas',
            'THES', 'RPRT', 'DATA', 'DBASE' => 'repositorio',
            'VIDEO', 'MPCT', 'SOUND', 'MUSIC' => 'multimedia',
            default => null !== $url && self::esVideo($url) ? 'multimedia' : 'sitio_web',
        };
        $sn = $primero('SN');
        $esIssn = null !== $sn && 1 === preg_match('/^\s*\d{4}-?\d{3}[\dXx]\s*$/', $sn);
        $paginas = $primero('SP');
        if (null !== $paginas && null !== ($fin = $primero('EP')) && !str_contains($paginas, '-')) {
            $paginas .= '-'.$fin;
        }
        $anio = $primero('PY', 'Y1', 'DA');

        return self::sinVacios([
            'titulo' => $primero('TI', 'T1', 'CT', 'BT') ?? '',
            'tipo' => $tipo,
            'url' => $url,
            'autores' => isset($r['AU']) || isset($r['A1']) ? implode('; ', array_merge($r['AU'] ?? [], $r['A1'] ?? [])) : null,
            'fuente' => 'articulo' === $tipo ? $primero('T2', 'JO', 'JF', 'JA', 'J2') : null,
            'anio' => null !== $anio ? substr($anio, 0, 4) : null,
            'volumen' => $primero('VL'),
            'fasciculo' => $primero('IS', 'CP'),
            'paginas' => $paginas,
            'doi' => $primero('DO'),
            'editorial' => $primero('PB'),
            'isbn' => $esIssn ? null : $sn,
            'issn' => $esIssn ? $sn : null,
            'descripcion' => $primero('AB', 'N2'),
            'codigo_norma' => 'normas' === $tipo ? $primero('M1', 'VL') : null,
        ]);
    }

    private static function esVideo(string $url): bool
    {
        return 1 === preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com|youtu\.be|vimeo\.com|player\.vimeo\.com)/~i', $url);
    }

    /** "Pérez, Ana and Soto, Luis" → "Pérez, Ana; Soto, Luis". */
    private static function autores(?string $autores, string $separador): ?string
    {
        if (null === $autores) {
            return null;
        }

        return implode('; ', array_filter(array_map('trim', preg_split('/'.preg_quote($separador, '/').'/i', $autores) ?: [])));
    }

    /**
     * @param array<string, ?string> $registro
     *
     * @return array<string, string>
     */
    private static function sinVacios(array $registro): array
    {
        return array_filter(
            array_map(fn (?string $v) => null === $v ? null : trim((string) preg_replace('/\s+/u', ' ', $v)), $registro),
            fn (?string $v) => null !== $v && '' !== $v,
        );
    }

    /** Convierte acentos y símbolos LaTeX habituales a texto: {\'a} → á, \~n → ñ, \& → &, y quita llaves. */
    public static function limpiarLatex(string $texto): string
    {
        $diacriticos = ["'" => "\u{0301}", '`' => "\u{0300}", '^' => "\u{0302}", '"' => "\u{0308}", '~' => "\u{0303}", 'c' => "\u{0327}", '=' => "\u{0304}", 'v' => "\u{030C}", 'u' => "\u{0306}", '.' => "\u{0307}", 'H' => "\u{030B}", 'r' => "\u{030A}"];
        $texto = (string) preg_replace_callback(
            '/\{?\\\\([\'`^"~=.]|[cvuHr](?=[\s{]))\s*\{?\s*(\\\\[ij]|[A-Za-z])\s*\}?\}?/',
            fn (array $m) => ltrim($m[2], '\\').$diacriticos[$m[1]],
            $texto,
        );
        $texto = strtr($texto, [
            '\\ss' => 'ß', '\\o' => 'ø', '\\O' => 'Ø', '\\ae' => 'æ', '\\AE' => 'Æ', '\\aa' => 'å', '\\AA' => 'Å', '\\l' => 'ł', '\\L' => 'Ł',
            '!`' => '¡', '?`' => '¿', '\\&' => '&', '\\%' => '%', '\\$' => '$', '\\_' => '_', '\\#' => '#', '---' => '—', '--' => '–', '~' => ' ',
        ]);
        $texto = str_replace(['{', '}'], '', $texto);
        if (class_exists(\Normalizer::class)) {
            $texto = (string) \Normalizer::normalize($texto, \Normalizer::FORM_C);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }
}
