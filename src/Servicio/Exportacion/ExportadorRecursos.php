<?php

declare(strict_types=1);

namespace App\Servicio\Exportacion;

use App\Entity\Materia;
use App\Entity\Recurso;
use App\Enum\FormatoRecurso;
use App\Enum\TipoRecurso;
use App\Servicio\Importacion\PlantillaImportacion;
use App\Servicio\ProxyAccesum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Exporta recursos de la lista A-Z:
 * - a Excel, con las mismas columnas de la plantilla de importación, para editarlo y volver a importarlo;
 * - a RIS, para gestores bibliográficos (Zotero, Mendeley, EndNote).
 */
final class ExportadorRecursos
{
    /** Tipo de registro RIS de cada tipo de recurso. */
    private const array TIPOS_RIS = [
        'base_datos' => 'DBASE',
        'revistas' => 'JFULL',
        'articulo' => 'JOUR',
        'libros' => 'BOOK',
        'repositorio' => 'DBASE',
        'normas' => 'STAND',
        'multimedia' => 'VIDEO',
        'herramienta' => 'COMP',
        'sitio_web' => 'ELEC',
    ];

    public function __construct(
        private readonly TranslatorInterface $traductor,
        private readonly ProxyAccesum $proxy,
    ) {
    }

    /**
     * Escribe el Excel. Todas las celdas van como texto: así Excel no convierte ISBN en números ni interpreta
     * como fórmula un título que empiece con «=».
     *
     * @param iterable<Recurso> $recursos
     */
    public function excel(iterable $recursos, string $destino, string $idioma): void
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle(mb_substr($this->traductor->trans('exportacion.hoja', [], null, $idioma), 0, 31));
        $hoja->fromArray(PlantillaImportacion::ENCABEZADOS, null, 'A1');

        $n = 1;
        foreach ($recursos as $recurso) {
            ++$n;
            foreach (array_values($this->filaExcel($recurso, $idioma)) as $i => $valor) {
                if (null !== $valor && '' !== $valor) {
                    $hoja->setCellValueExplicit([$i + 1, $n], $valor, DataType::TYPE_STRING);
                }
            }
        }

        $columnas = \count(PlantillaImportacion::ENCABEZADOS);
        $hoja->getStyle('A1:'.Coordinate::stringFromColumnIndex($columnas).'1')->getFont()->setBold(true);
        for ($i = 1; $i <= $columnas; ++$i) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        $hoja->freezePane('A2');

        (new Xlsx($libro))->save($destino);
    }

    /**
     * Una fila del Excel, en el orden de PlantillaImportacion::ENCABEZADOS.
     *
     * @return array<string, ?string>
     */
    public function filaExcel(Recurso $recurso, string $idioma): array
    {
        $t = fn (string $clave): string => $this->traductor->trans($clave, [], null, $idioma);
        $siNo = fn (bool $valor): string => $t($valor ? 'formulario.si' : 'formulario.no');
        $tipo = $recurso->getTipo();
        $usa = fn (string $campo): bool => $tipo->usaCampo($campo);

        $fila = [
            'titulo' => $recurso->getTitulo(),
            'url' => $recurso->getUrl(),
            'proxy' => $siNo($recurso->isRequiereProxy()),
            'tipo' => $t($tipo->etiqueta()),
            'acceso' => $t($recurso->getAcceso()->etiqueta()),
            'formato' => $tipo->tieneFormato() && null !== $recurso->getFormato() ? $t($recurso->getFormato()->etiqueta()) : null,
            'proveedor' => $recurso->getProveedor(),
            'materias' => implode('; ', $this->materias($recurso, $idioma)),
            'descripcion' => $recurso->getDescripcion(),
            'descripcion_en' => $recurso->getDescripcionEn(),
            'nombres_alternativos' => $recurso->getNombresAlternativos(),
            'visible' => $siNo($recurso->isVisible()),
            'prueba_hasta' => $recurso->getPruebaHasta()?->format('d-m-Y'),
            'autores' => $usa('autores') ? $recurso->getAutoresTexto() : null,
            'editorial' => $usa('editorial') ? $recurso->getEditorial()?->getNombre() : null,
            'anio' => $usa('anio') && null !== $recurso->getAnio() ? (string) $recurso->getAnio() : null,
            'isbn' => $usa('isbn') ? $recurso->getIsbn() : null,
            'eisbn' => $usa('eisbn') ? $recurso->getEisbn() : null,
            'issn' => $usa('issn') ? $recurso->getIssn() : null,
            'eissn' => $usa('eissn') ? $recurso->getEissn() : null,
            'disponible_desde' => $usa('disponibleDesde') ? $recurso->getDisponibleDesde()?->format('d-m-Y') : null,
            'codigo_norma' => $usa('codigoNorma') ? $recurso->getCodigoNorma() : null,
            'organismo' => $usa('organismo') ? $recurso->getOrganismo() : null,
            'fuente' => $usa('fuente') ? $recurso->getFuente() : null,
            'volumen' => $usa('volumen') ? $recurso->getVolumen() : null,
            'fasciculo' => $usa('fasciculo') ? $recurso->getFasciculo() : null,
            'paginas' => $usa('paginas') ? $recurso->getPaginas() : null,
            'doi' => $usa('doi') ? $recurso->getDoi() : null,
        ];
        \assert(\count($fila) === \count(PlantillaImportacion::ENCABEZADOS));

        return $fila;
    }

    /**
     * Archivo RIS (una entrada por recurso, líneas «XX  - valor» terminadas en CRLF, como pide la especificación).
     *
     * @param iterable<Recurso> $recursos
     */
    public function ris(iterable $recursos, string $idioma): string
    {
        $salida = '';
        foreach ($recursos as $recurso) {
            foreach ($this->entradaRis($recurso, $idioma) as [$etiqueta, $valor]) {
                $salida .= $etiqueta.'  - '.$valor."\r\n";
            }
            $salida .= "\r\n";
        }

        return $salida;
    }

    /** @return list<array{0: string, 1: string}> */
    public function entradaRis(Recurso $recurso, string $idioma): array
    {
        $tipo = $recurso->getTipo();
        $usa = fn (string $campo): bool => $tipo->usaCampo($campo);
        $tipoRis = self::TIPOS_RIS[$tipo->value];
        if (TipoRecurso::Libros === $tipo && FormatoRecurso::Electronico === $recurso->getFormato()) {
            $tipoRis = 'EBOOK';
        }

        $lineas = [['TY', $tipoRis], ['TI', $recurso->getTitulo()]];
        if ($usa('autores')) {
            foreach ($recurso->getAutores() as $autor) {
                $lineas[] = ['AU', $autor->getNombreCompleto()];
            }
        }
        if ($usa('fuente')) {
            $lineas[] = ['T2', $recurso->getFuente()];
        }
        if ($usa('anio') && null !== $recurso->getAnio()) {
            $lineas[] = ['PY', (string) $recurso->getAnio()];
        }
        if ($usa('volumen')) {
            $lineas[] = ['VL', $recurso->getVolumen()];
        }
        if ($usa('fasciculo')) {
            $lineas[] = ['IS', $recurso->getFasciculo()];
        }
        if ($usa('paginas') && null !== $recurso->getPaginas()) {
            $paginas = array_map('trim', preg_split('/\s*[-–]\s*/u', $recurso->getPaginas(), 2) ?: []);
            $lineas[] = ['SP', $paginas[0] ?? null];
            $lineas[] = ['EP', $paginas[1] ?? null];
        }
        if ($usa('editorial')) {
            $lineas[] = ['PB', $recurso->getEditorial()?->getNombre()];
        }
        if ($usa('organismo')) {
            $lineas[] = ['PB', $recurso->getOrganismo()];
        }
        if ($usa('codigoNorma')) {
            $lineas[] = ['M1', $recurso->getCodigoNorma()];
        }
        foreach (['isbn', 'eisbn', 'issn', 'eissn'] as $campo) {
            if ($usa($campo)) {
                $lineas[] = ['SN', $recurso->{'get'.ucfirst($campo)}()];
            }
        }
        if ($usa('doi')) {
            $lineas[] = ['DO', $recurso->getDoi()];
        }
        // El enlace que usa la persona: con el proxy si el recurso lo requiere (acceso desde fuera del campus).
        $lineas[] = ['UR', $this->proxy->enlace($recurso)];
        $lineas[] = ['DP', $recurso->getProveedor()];
        $lineas[] = ['AB', $recurso->getDescripcionTraducida($idioma)];
        foreach ($this->materias($recurso, $idioma) as $materia) {
            $lineas[] = ['KW', $materia];
        }
        $lineas[] = ['ER', ''];

        // RIS no admite saltos de línea dentro de un valor: se reemplazan por espacios.
        return array_values(array_filter(
            array_map(fn (array $l) => [$l[0], trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', (string) $l[1]))], $lineas),
            fn (array $l) => '' !== $l[1] || 'ER' === $l[0],
        ));
    }

    /** @return list<string> nombres de las materias en el idioma pedido, ordenados */
    private function materias(Recurso $recurso, string $idioma): array
    {
        $nombres = array_map(
            fn (Materia $m) => 'en' === $idioma && null !== $m->getNombreEn() && '' !== $m->getNombreEn() ? $m->getNombreEn() : $m->getNombre(),
            $recurso->getMaterias()->toArray(),
        );
        sort($nombres, \SORT_NATURAL | \SORT_FLAG_CASE);

        return array_values(array_unique($nombres));
    }
}
