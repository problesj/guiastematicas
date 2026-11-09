<?php

declare(strict_types=1);

namespace App\Servicio\Importacion;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Genera el Excel de ejemplo con las columnas que reconoce el importador. */
final class PlantillaImportacion
{
    public const array ENCABEZADOS = [
        'Título', 'URL', 'Requiere proxy', 'Tipo', 'Acceso', 'Proveedor', 'Materias',
        'Descripción', 'Descripción en inglés', 'Nombres alternativos', 'Visible', 'Prueba hasta',
        'Autores', 'Editorial', 'Año de edición', 'ISBN', 'e-ISBN', 'ISSN', 'e-ISSN', 'Disponible desde',
        'Código de la norma', 'Organismo emisor',
    ];

    public function escribir(string $destino): void
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Recursos bibliográficos');
        $hoja->fromArray([
            self::ENCABEZADOS,
            ['Scopus', 'https://www.scopus.com', 'Sí', 'Base de datos', 'Suscripción', 'Elsevier', 'Ingeniería Civil; Medicina',
                'Base de datos de resúmenes y citas de literatura científica.', 'Abstract and citation database.', 'SciVerse Scopus', 'Sí', '',
                '', '', '', '', '', '', '', '01-01-1996', '', ''],
            ['Revista Médica de Chile', 'https://www.scielo.cl/scielo.php?script=sci_serial&pid=0034-9887', 'No', 'Revistas', 'Abierto', 'SciELO', 'Medicina',
                'Revista de la Sociedad Médica de Santiago.', '', '', 'Sí', '',
                '', 'Sociedad Médica de Santiago', '', '', '', '0034-9887', '0717-6163', '01-01-2000', '', ''],
            ['Introducción a la programación', 'https://ebookcentral.proquest.com/lib/ucn/detail.action?docID=1', 'Sí', 'Libros', 'Suscripción', 'ProQuest Ebook Central', 'Ingeniería Civil',
                '', '', '', 'Sí', '',
                'Pérez, Ana; Soto, Luis', 'Editorial Universitaria', '2021', '978-956-11-2345-8', '978-956-11-2346-5', '', '', '', '', ''],
            ['Hormigón - Requisitos generales', 'https://ecommerce.inn.cl/', 'Sí', 'Normas', 'Suscripción', 'INN', 'Ingeniería Civil',
                '', '', '', 'Sí', '',
                '', '', '2016', '', '', '', '', '', 'NCh 170', 'INN'],
        ], null, 'A1', true);
        // ISBN e ISSN como texto, para que Excel no los convierta en números.
        $ultima = Coordinate::stringFromColumnIndex(\count(self::ENCABEZADOS));
        $hoja->getStyle('P2:S100')->getNumberFormat()->setFormatCode('@');
        $hoja->getStyle('A1:'.$ultima.'1')->getFont()->setBold(true);
        for ($i = 1; $i <= \count(self::ENCABEZADOS); ++$i) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        $hoja->freezePane('A2');

        (new Xlsx($libro))->save($destino);
    }
}
