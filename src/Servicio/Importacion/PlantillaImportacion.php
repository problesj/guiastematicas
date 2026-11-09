<?php

declare(strict_types=1);

namespace App\Servicio\Importacion;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Genera el Excel de ejemplo con las columnas que reconoce el importador. */
final class PlantillaImportacion
{
    public const array ENCABEZADOS = [
        'Título', 'URL', 'Requiere proxy', 'Tipo', 'Acceso', 'Proveedor', 'Materias',
        'Descripción', 'Descripción en inglés', 'Nombres alternativos', 'Visible', 'Prueba hasta',
    ];

    public function escribir(string $destino): void
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Bases de datos');
        $hoja->fromArray([
            self::ENCABEZADOS,
            ['Scopus', 'https://www.scopus.com', 'Sí', 'Base de datos', 'Suscripción', 'Elsevier', 'Ingeniería Civil; Medicina',
                'Base de datos de resúmenes y citas de literatura científica.', 'Abstract and citation database.', 'SciVerse Scopus', 'Sí', ''],
            ['SciELO Chile', 'https://www.scielo.cl', 'No', 'Revistas', 'Abierto', 'SciELO', 'Medicina',
                'Revistas científicas chilenas de acceso abierto.', '', '', 'Sí', ''],
        ]);
        $hoja->getStyle('A1:L1')->getFont()->setBold(true);
        foreach (range('A', 'L') as $columna) {
            $hoja->getColumnDimension($columna)->setAutoSize(true);
        }
        $hoja->freezePane('A2');

        (new Xlsx($libro))->save($destino);
    }
}
