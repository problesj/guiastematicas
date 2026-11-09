<?php

declare(strict_types=1);

namespace App\Servicio\Importacion;

use App\Entity\Materia;
use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Repository\MateriaRepository;
use App\Repository\RecursoRepository;
use App\Servicio\GeneradorSlug;
use App\Servicio\ProxyAccesum;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints\Isbn;
use Symfony\Component\Validator\Constraints\Issn;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Importa la lista A-Z desde Excel (.xlsx, .xls, .ods) o CSV.
 *
 * Las columnas se reconocen por su encabezado, en español o inglés y sin importar tildes ni mayúsculas
 * (ver COLUMNAS). Solo "titulo" y "url" son obligatorias. Un recurso existente (misma URL o mismo título)
 * se actualiza; las materias que no existen se crean.
 */
final class ImportadorRecursos
{
    /** Campo => encabezados aceptados, ya normalizados (minúsculas, sin tildes ni signos). */
    public const array COLUMNAS = [
        'titulo' => ['titulo', 'nombre', 'basededatos', 'basesdedatos', 'recurso', 'title', 'name', 'database', 'nombredelrecurso'],
        'url' => ['url', 'enlace', 'link', 'direccion', 'urlacceso', 'urloriginal', 'sitio'],
        'requiere_proxy' => ['proxy', 'requiereproxy', 'accesum', 'viaproxy', 'usaproxy', 'accesoremoto'],
        'descripcion' => ['descripcion', 'description', 'resumen', 'contenido', 'descripcionespanol'],
        'descripcion_en' => ['descripcionen', 'descripcioningles', 'descriptionen', 'englishdescription'],
        'proveedor' => ['proveedor', 'plataforma', 'vendor', 'proveedoroplataforma'],
        'tipo' => ['tipo', 'formato', 'type', 'tipoderecurso'],
        'acceso' => ['acceso', 'tipodeacceso', 'access', 'licencia'],
        'materias' => ['materias', 'materia', 'areas', 'area', 'disciplinas', 'disciplina', 'subjects', 'carreras', 'carrera'],
        'nombres_alternativos' => ['nombresalternativos', 'alias', 'siglas', 'otrosnombres'],
        'visible' => ['visible', 'activo', 'activa', 'mostrar', 'publicado'],
        'prueba_hasta' => ['pruebahasta', 'finprueba', 'finperiododeprueba', 'trialuntil'],
        // Datos bibliográficos: se guardan solo si corresponden al tipo (ver TipoRecurso::campos()).
        'autores' => ['autores', 'autor', 'autoria', 'responsables', 'authors', 'author'],
        'editorial' => ['editorial', 'editor', 'casaeditora', 'publisher'],
        'fuente' => ['fuente', 'revista', 'publicadoen', 'nombredelarevista', 'source', 'journal', 'sourcetitle'],
        'volumen' => ['volumen', 'vol', 'volume'],
        'fasciculo' => ['fasciculo', 'numero', 'num', 'issue', 'number'],
        'paginas' => ['paginas', 'pagina', 'pags', 'pp', 'pages'],
        'doi' => ['doi'],
        'anio' => ['ano', 'anio', 'anodeedicion', 'anoedicion', 'anodepublicacion', 'year', 'publicationyear'],
        'isbn' => ['isbn', 'isbnimpreso', 'printisbn'],
        'eisbn' => ['eisbn', 'isbne', 'isbnelectronico', 'onlineisbn', 'electronicisbn'],
        'issn' => ['issn', 'issnimpreso', 'printissn'],
        'eissn' => ['eissn', 'issne', 'issnelectronico', 'onlineissn', 'electronicissn'],
        'disponible_desde' => ['disponibledesde', 'fechadedisponibilidad', 'fechadisponibilidad', 'accesodesde', 'coberturadesde', 'availablefrom'],
        'codigo_norma' => ['codigonorma', 'codigodelanorma', 'numerodenorma', 'standardnumber', 'standardcode'],
        'organismo' => ['organismo', 'organismoemisor', 'entidademisora', 'issuingbody'],
    ];

    /** Columna del archivo => setter de Recurso, para los campos de texto libre. */
    private const array CAMPOS_TEXTO = [
        'descripcion' => ['setDescripcion', 3000],
        'descripcion_en' => ['setDescripcionEn', 3000],
        'proveedor' => ['setProveedor', 120],
        'nombres_alternativos' => ['setNombresAlternativos', 255],
        'autores' => ['setAutores', 500],
        'editorial' => ['setEditorial', 150],
        'fuente' => ['setFuente', 255],
        'volumen' => ['setVolumen', 20],
        'fasciculo' => ['setFasciculo', 20],
        'paginas' => ['setPaginas', 30],
        'codigo_norma' => ['setCodigoNorma', 60],
        'organismo' => ['setOrganismo', 120],
    ];

    /** Columna => [setter, restricción] para los identificadores que se validan; uno no válido se descarta con aviso. */
    private const array IDENTIFICADORES = [
        'isbn' => ['setIsbn', 'isbn'],
        'eisbn' => ['setEisbn', 'isbn'],
        'issn' => ['setIssn', 'issn'],
        'eissn' => ['setEissn', 'issn'],
    ];

    public const int MAXIMO_FILAS = 5000;

    /**
     * Prefijos de proxy habituales que se reconocen aunque no coincidan con PROXY_URL:
     * EZproxy (https://host/login?url=...) y MuseKnowledge (https://host/APP?url=... o ?qurl=... codificada).
     */
    private const string PATRON_PROXY = '~^https?://[^/\s]+(?:/[^?\s]*)?\?q?url=(.+)$~i';

    public function __construct(
        private readonly RecursoRepository $recursos,
        private readonly MateriaRepository $materiasRepo,
        private readonly EntityManagerInterface $em,
        private readonly ProxyAccesum $proxy,
        private readonly GeneradorSlug $slugs,
        private readonly ValidatorInterface $validador,
        private readonly LectorBibliografia $lectorBibliografia,
    ) {
    }

    /**
     * @return list<FilaImportacion>
     *
     * @throws ArchivoNoValidoException si el archivo no se puede leer o no tiene las columnas mínimas
     */
    public function leer(string $ruta): array
    {
        if (\in_array(strtolower(pathinfo($ruta, \PATHINFO_EXTENSION)), LectorBibliografia::EXTENSIONES, true)) {
            return $this->leerBibliografia($ruta);
        }

        try {
            // Sin READ_DATA_ONLY para conservar los hipervínculos de las celdas.
            $hoja = IOFactory::load($ruta, 0, ['Xlsx', 'Xls', 'Ods', 'Csv'])->getActiveSheet();
        } catch (\Throwable) {
            throw new ArchivoNoValidoException('importacion.archivo_ilegible');
        }

        [$filaEncabezado, $columnas] = $this->detectarEncabezados($hoja);
        if (!isset($columnas['titulo']) || (!isset($columnas['url']) && !$this->tituloTieneHipervinculos($hoja, $columnas['titulo'], $filaEncabezado))) {
            throw new ArchivoNoValidoException('importacion.sin_columnas');
        }

        $ultimaFila = $hoja->getHighestDataRow();
        if ($ultimaFila - $filaEncabezado > self::MAXIMO_FILAS) {
            throw new ArchivoNoValidoException('importacion.demasiadas_filas');
        }

        $filas = [];
        for ($n = $filaEncabezado + 1; $n <= $ultimaFila; ++$n) {
            $crudo = function (string $campo) use ($hoja, $columnas, $n): mixed {
                return isset($columnas[$campo]) && $hoja->cellExists($columnas[$campo].$n) ? $hoja->getCell($columnas[$campo].$n)->getValue() : null;
            };
            $valor = function (string $campo) use ($hoja, $columnas, $n): ?string {
                if (!isset($columnas[$campo]) || !$hoja->cellExists($columnas[$campo].$n)) {
                    return null;
                }
                $texto = trim((string) $hoja->getCell($columnas[$campo].$n)->getFormattedValue());

                return '' === $texto ? null : $texto;
            };
            // Si no hay columna URL, o viene vacía, se usa el hipervínculo del título (común en listas en Excel).
            $hipervinculo = null;
            if ($hoja->cellExists($columnas['titulo'].$n) && $hoja->getCell($columnas['titulo'].$n)->hasHyperlink()) {
                $hipervinculo = trim($hoja->getCell($columnas['titulo'].$n)->getHyperlink()->getUrl());
            }

            $filas[] = $this->interpretarFila($n, array_keys($columnas), $valor, $crudo, $hipervinculo);
        }

        return $this->revisarFilas($filas);
    }

    /**
     * Lee un archivo BibTeX (.bib) o RIS (.ris), exportado por ejemplo desde Zotero, Mendeley, EndNote o una base de datos.
     *
     * @return list<FilaImportacion>
     */
    private function leerBibliografia(string $ruta): array
    {
        $registros = $this->lectorBibliografia->leer($ruta);
        if ([] === $registros) {
            throw new ArchivoNoValidoException('importacion.bibliografia_vacia');
        }
        if (\count($registros) > self::MAXIMO_FILAS) {
            throw new ArchivoNoValidoException('importacion.demasiadas_filas');
        }

        $filas = [];
        foreach ($registros as $i => $registro) {
            $valor = fn (string $campo): ?string => $registro[$campo] ?? null;
            $filas[] = $this->interpretarFila($i + 1, array_keys($registro), $valor, $valor, null);
        }

        return $this->revisarFilas($filas);
    }

    /**
     * Quita filas vacías, marca URL repetidas dentro del archivo y busca los recursos que ya existen.
     *
     * @param list<FilaImportacion|null> $filas
     *
     * @return list<FilaImportacion>
     */
    private function revisarFilas(array $filas): array
    {
        $revisadas = [];
        $vistas = [];
        foreach (array_filter($filas) as $fila) {
            $clave = mb_strtolower((string) ($fila->datos['url'] ?? ''));
            if ('' !== $clave && isset($vistas[$clave])) {
                $fila->errores[] = 'importacion.error_repetido|'.$vistas[$clave];
            } elseif ('' !== $clave) {
                $vistas[$clave] = $fila->numero;
            }

            if ($fila->esValida()) {
                $fila->existente = $this->recursos->buscarParaImportar($fila->datos['url'], $fila->datos['titulo']);
            }
            $revisadas[] = $fila;
        }

        return $revisadas;
    }

    /**
     * Crea o actualiza los recursos de las filas válidas.
     *
     * @param list<FilaImportacion> $filas
     *
     * @return array{creados: int, actualizados: int, omitidos: int, materias_nuevas: int}
     */
    public function aplicar(array $filas): array
    {
        $resultado = ['creados' => 0, 'actualizados' => 0, 'omitidos' => 0, 'materias_nuevas' => 0];
        $materias = $this->indiceMaterias();

        foreach ($filas as $fila) {
            if (!$fila->esValida()) {
                ++$resultado['omitidos'];
                continue;
            }

            $recurso = $fila->existente ?? new Recurso();
            $d = $fila->datos;
            $recurso->setTitulo($d['titulo'])->setUrl($d['url'])->setRequiereProxy($d['requiere_proxy']);
            foreach (self::CAMPOS_TEXTO + self::IDENTIFICADORES as $campo => [$metodo]) {
                if (\array_key_exists($campo, $d)) {
                    $recurso->{$metodo}($d[$campo]);
                }
            }
            if (\array_key_exists('anio', $d)) {
                $recurso->setAnio($d['anio']);
            }
            if (\array_key_exists('doi', $d)) {
                $recurso->setDoi($d['doi']);
            }
            if (\array_key_exists('disponible_desde', $d)) {
                $recurso->setDisponibleDesde($d['disponible_desde']);
            }
            if (isset($d['tipo'])) {
                $recurso->setTipo($d['tipo']);
            }
            if (isset($d['acceso'])) {
                $recurso->setAcceso($d['acceso']);
            }
            if (\array_key_exists('visible', $d)) {
                $recurso->setVisible($d['visible']);
            }
            if (\array_key_exists('prueba_hasta', $d)) {
                $recurso->setPruebaHasta($d['prueba_hasta']);
            }
            // En listas de bases de datos la columna "Editorial" suele ser el proveedor.
            if (null !== $recurso->getEditorial() && !$recurso->getTipo()->usaCampo('editorial') && null === $recurso->getProveedor()) {
                $recurso->setProveedor(mb_substr($recurso->getEditorial(), 0, 120));
            }
            $recurso->limpiarCamposNoAplicables();

            foreach ($fila->materias as $nombre) {
                $clave = self::normalizar($nombre);
                if (!isset($materias[$clave])) {
                    $materia = (new Materia())->setNombre($nombre);
                    $materia->setSlug($this->slugUnico($nombre, $materias));
                    $this->em->persist($materia);
                    $materias[$clave] = $materia;
                    ++$resultado['materias_nuevas'];
                }
                $recurso->addMateria($materias[$clave]);
            }

            if (null === $fila->existente) {
                $this->em->persist($recurso);
                ++$resultado['creados'];
            } else {
                ++$resultado['actualizados'];
            }
        }

        $this->em->flush();

        return $resultado;
    }

    /** Quita tildes, mayúsculas y todo lo que no sea letra o número: "Base de Datos" → "basededatos". */
    public static function normalizar(string $texto): string
    {
        return preg_replace('/[^a-z0-9]/', '', (new AsciiSlugger())->slug($texto)->lower()->toString()) ?? '';
    }

    /**
     * Busca la fila de encabezados entre las 10 primeras: la primera que tenga al menos dos columnas conocidas.
     *
     * @return array{0: int, 1: array<string, string>} número de fila y campo => letra de columna
     */
    private function detectarEncabezados(Worksheet $hoja): array
    {
        $ultimaColumna = $hoja->getHighestDataColumn();
        for ($n = 1; $n <= min(10, $hoja->getHighestDataRow()); ++$n) {
            $columnas = [];
            foreach ($hoja->getRowIterator($n, $n)->current()->getCellIterator('A', $ultimaColumna) as $celda) {
                $encabezado = self::normalizar((string) $celda->getValue());
                foreach (self::COLUMNAS as $campo => $aceptados) {
                    if ('' !== $encabezado && !isset($columnas[$campo]) && \in_array($encabezado, $aceptados, true)) {
                        $columnas[$campo] = $celda->getColumn();
                        break;
                    }
                }
            }
            if (\count($columnas) >= 2 || isset($columnas['titulo'])) {
                return [$n, $columnas];
            }
        }

        return [1, []];
    }

    private function tituloTieneHipervinculos(Worksheet $hoja, string $columna, int $filaEncabezado): bool
    {
        $siguiente = $filaEncabezado + 1;

        return $hoja->cellExists($columna.$siguiente) && $hoja->getCell($columna.$siguiente)->hasHyperlink();
    }

    /**
     * Interpreta un registro, venga de una planilla o de un archivo bibliográfico.
     *
     * @param list<string>                  $presentes campos que trae el archivo (un campo presente y vacío borra el valor al actualizar)
     * @param \Closure(string): ?string     $valor     texto del campo, o null si está vacío
     * @param \Closure(string): mixed       $crudo     valor sin formato (para fechas de Excel)
     */
    private function interpretarFila(int $n, array $presentes, \Closure $valor, \Closure $crudo, ?string $hipervinculo): ?FilaImportacion
    {
        $hay = fn (string $campo): bool => \in_array($campo, $presentes, true);
        $titulo = $valor('titulo');
        $doi = null !== $valor('doi') ? (new Recurso())->setDoi($valor('doi'))->getDoi() : null;
        // Sin URL: el hipervínculo del título (Excel) o el enlace del DOI (artículos).
        $url = $valor('url') ?? $hipervinculo ?? (null !== $doi ? 'https://doi.org/'.$doi : null);

        if (null === $titulo && null === $url) {
            return null; // fila vacía
        }

        $fila = new FilaImportacion($n, ['titulo' => (string) $titulo]);
        if (null === $titulo) {
            $fila->errores[] = 'importacion.error_sin_titulo';
        } elseif (mb_strlen($titulo) > 200) {
            $fila->errores[] = 'importacion.error_titulo_largo';
        }

        $traiaProxy = $this->interpretarUrl($fila, $url);

        foreach (self::CAMPOS_TEXTO as $campo => [, $largo]) {
            if ($hay($campo)) {
                $texto = $valor($campo);
                $fila->datos[$campo] = null === $texto ? null : mb_substr($texto, 0, $largo);
            }
        }
        foreach (self::IDENTIFICADORES as $campo => [, $tipoIdentificador]) {
            if ($hay($campo)) {
                $fila->datos[$campo] = $this->interpretarIdentificador($valor($campo), $tipoIdentificador, $fila);
            }
        }
        if ($hay('doi')) {
            $fila->datos['doi'] = $doi;
            if (null !== $doi && 1 !== preg_match('~^10\.\d{4,9}/\S+$~', $doi)) {
                $fila->avisos[] = 'importacion.aviso_doi|'.$doi;
                $fila->datos['doi'] = null;
            }
        }
        if ($hay('anio')) {
            $fila->datos['anio'] = self::interpretarAnio($valor('anio'), $fila);
        }
        if ($hay('disponible_desde')) {
            $fila->datos['disponible_desde'] = $this->interpretarFecha($crudo('disponible_desde'), $fila);
        }

        if (null !== $tipo = $valor('tipo')) {
            $fila->datos['tipo'] = self::interpretarTipo($tipo) ?? TipoRecurso::BaseDatos;
            if (null === self::interpretarTipo($tipo)) {
                $fila->avisos[] = 'importacion.aviso_tipo|'.$tipo;
            }
        }
        if (null !== $acceso = $valor('acceso')) {
            $fila->datos['acceso'] = self::interpretarAcceso($acceso);
        }
        if ($hay('visible')) {
            $fila->datos['visible'] = self::interpretarBooleano($valor('visible')) ?? true;
        }
        if ($hay('prueba_hasta')) {
            $fila->datos['prueba_hasta'] = $this->interpretarFecha($crudo('prueba_hasta'), $fila);
        }

        // Requiere proxy: lo que diga la columna; si no hay, sí cuando la URL traía el proxy o el recurso no es de acceso abierto.
        $fila->datos['requiere_proxy'] = self::interpretarBooleano($valor('requiere_proxy'))
            ?? ($traiaProxy || TipoAcceso::Abierto !== ($fila->datos['acceso'] ?? TipoAcceso::Suscripcion));

        if (null !== $materias = $valor('materias')) {
            $nombres = array_map('trim', preg_split('/[;,|\/\n]+/', $materias) ?: []);
            $fila->materias = array_values(array_unique(array_filter($nombres, fn (string $m) => '' !== $m && mb_strlen($m) <= 150)));
        }

        return $fila;
    }

    /** Valida y guarda la URL original. Devuelve true si venía con el prefijo del proxy. */
    private function interpretarUrl(FilaImportacion $fila, ?string $url): bool
    {
        if (null === $url) {
            $fila->errores[] = 'importacion.error_sin_url';

            return false;
        }

        // Si la URL viene con el prefijo del proxy, se guarda la original y se marca "requiere proxy".
        $original = $this->proxy->quitar($url);
        if (null === $original && 1 === preg_match(self::PATRON_PROXY, $url, $coincidencia)) {
            $original = rawurldecode($coincidencia[1]);
        }
        $traiaProxy = null !== $original;
        $url = $original ?? $url;

        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = 'https://'.$url;
        }
        if (false === filter_var($url, \FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url)) {
            $fila->errores[] = 'importacion.error_url|'.$url;
        } elseif (mb_strlen($url) > 1000) {
            $fila->errores[] = 'importacion.error_url_larga';
        }

        $fila->datos['url'] = $url;
        if ($traiaProxy) {
            $fila->avisos[] = 'importacion.aviso_proxy_quitado';
        }

        return $traiaProxy;
    }

    /** Devuelve el ISBN o ISSN si es válido; si no, lo descarta con un aviso (la fila se importa igual). */
    private function interpretarIdentificador(?string $valor, string $tipo, FilaImportacion $fila): ?string
    {
        if (null === $valor) {
            return null;
        }
        $limpio = strtoupper((string) preg_replace('/\s+/', '', $valor));
        $restriccion = 'isbn' === $tipo ? new Isbn() : new Issn();
        if (\count($this->validador->validate($limpio, $restriccion)) > 0) {
            $fila->avisos[] = 'importacion.aviso_'.$tipo.'|'.$valor;

            return null;
        }

        return $limpio;
    }

    /** Acepta "2021" o una fecha de la que se toma el año (4 dígitos entre 1450 y 2100). */
    private static function interpretarAnio(?string $valor, FilaImportacion $fila): ?int
    {
        if (null === $valor) {
            return null;
        }
        if (1 === preg_match('/\b(1[4-9]\d{2}|20\d{2}|2100)\b/', $valor, $coincidencia) && (int) $coincidencia[1] >= 1450) {
            return (int) $coincidencia[1];
        }
        $fila->avisos[] = 'importacion.aviso_anio|'.$valor;

        return null;
    }

    /** Fecha desde Excel (número de serie) o texto dd-mm-aaaa, dd/mm/aaaa o aaaa-mm-dd. */
    private function interpretarFecha(mixed $valor, FilaImportacion $fila): ?\DateTimeImmutable
    {
        if (null === $valor || '' === $valor) {
            return null;
        }
        if (\is_int($valor) || \is_float($valor)) {
            return \DateTimeImmutable::createFromMutable(FechaExcel::excelToDateTimeObject((float) $valor))->setTime(0, 0);
        }
        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d'] as $formato) {
            $fecha = \DateTimeImmutable::createFromFormat('!'.$formato, trim((string) $valor));
            if (false !== $fecha) {
                return $fecha;
            }
        }
        $fila->avisos[] = 'importacion.aviso_fecha|'.$valor;

        return null;
    }

    public static function interpretarBooleano(?string $valor): ?bool
    {
        if (null === $valor) {
            return null;
        }

        return match (self::normalizar($valor)) {
            'si', 's', 'yes', 'y', 'x', '1', 'true', 'verdadero', 'v' => true,
            'no', 'n', '0', 'false', 'falso', 'f' => false,
            default => null,
        };
    }

    public static function interpretarTipo(string $valor): ?TipoRecurso
    {
        $v = self::normalizar($valor);

        return match (true) {
            str_contains($v, 'articul') || str_contains($v, 'article') || str_contains($v, 'paper') => TipoRecurso::Articulo,
            str_contains($v, 'revist') || str_contains($v, 'journal') => TipoRecurso::Revistas,
            str_contains($v, 'libro') || str_contains($v, 'book') => TipoRecurso::Libros,
            str_contains($v, 'repositori') => TipoRecurso::Repositorio,
            str_contains($v, 'norma') || str_contains($v, 'standard') => TipoRecurso::Normas,
            str_contains($v, 'video') || str_contains($v, 'multimedia') || str_contains($v, 'audio') || str_contains($v, 'imagen') => TipoRecurso::Multimedia,
            str_contains($v, 'herramienta') || str_contains($v, 'gestor') || str_contains($v, 'tool') || str_contains($v, 'software') => TipoRecurso::Herramienta,
            str_contains($v, 'sitio') || str_contains($v, 'web') || str_contains($v, 'portal') => TipoRecurso::SitioWeb,
            str_contains($v, 'base') || str_contains($v, 'database') || str_contains($v, 'bd') => TipoRecurso::BaseDatos,
            default => null,
        };
    }

    public static function interpretarAcceso(string $valor): TipoAcceso
    {
        $v = self::normalizar($valor);

        return match (true) {
            str_contains($v, 'abiert') || str_contains($v, 'open') || str_contains($v, 'libre') || str_contains($v, 'gratuit') || str_contains($v, 'free') => TipoAcceso::Abierto,
            str_contains($v, 'prueba') || str_contains($v, 'trial') => TipoAcceso::Prueba,
            default => TipoAcceso::Suscripcion,
        };
    }

    /** @return array<string, Materia> nombre o slug normalizado => materia */
    private function indiceMaterias(): array
    {
        $indice = [];
        foreach ($this->materiasRepo->findAll() as $materia) {
            $indice[self::normalizar($materia->getNombre())] = $materia;
            $indice[self::normalizar($materia->getSlug())] ??= $materia;
            if (null !== $materia->getNombreEn()) {
                $indice[self::normalizar($materia->getNombreEn())] ??= $materia;
            }
        }

        return $indice;
    }

    /** @param array<string, Materia> $materias */
    private function slugUnico(string $nombre, array $materias): string
    {
        $usados = array_map(fn (Materia $m) => $m->getSlug(), $materias);
        $base = $this->slugs->generar(Materia::class, $nombre);
        $slug = $base;
        for ($n = 2; \in_array($slug, $usados, true); ++$n) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
