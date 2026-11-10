<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Responde en las pruebas como lo harían las API de Primo VE y Alma (formato JSON de Ex Libris).
 *
 * Conoce un libro propio de la biblioteca (Cormen, con ejemplares) y uno solo del índice central (sin ejemplares).
 */
final class CatalogoFalso
{
    public const MMS_CORMEN = '991000000001234';

    /** @var list<string> direcciones consultadas */
    public array $consultas = [];

    public function __invoke(string $metodo, string $url): MockResponse
    {
        $this->consultas[] = $url;
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        if (str_contains($url, '/primo/v1/search')) {
            if ('clave-primo' !== ($query['apikey'] ?? null)) {
                return new MockResponse('{"errorsExist":true}', ['http_code' => 401]);
            }

            return new MockResponse(json_encode(['info' => ['total' => 1], 'docs' => match ($query['q'] ?? '') {
                'isbn,exact,9780262033848' => [
                    ['context' => 'PC', 'pnx' => ['control' => ['recordid' => ['cdi_otro']], 'display' => ['title' => ['Otra edición']]]],
                    ['context' => 'L', 'pnx' => [
                        'control' => ['recordid' => ['alma'.self::MMS_CORMEN]],
                        'display' => [
                            'title' => ['Introduction to algorithms /'],
                            'creator' => ['Cormen, Thomas H.$$QCormen, Thomas H.'],
                            'publisher' => ['Cambridge, Mass. : MIT Press'],
                            'creationdate' => ['c2009'],
                            'mms' => [self::MMS_CORMEN],
                        ],
                    ]],
                ],
                'isbn,exact,9780134685991' => [
                    ['context' => 'PC', 'pnx' => [
                        'control' => ['recordid' => ['cdi_proquest_9780134685991']],
                        'display' => ['title' => ['Effective Java'], 'creator' => ['Bloch, Joshua']],
                        'addata' => ['date' => ['2018']],
                    ]],
                ],
                'isbn,exact,0000000000' => throw new \RuntimeException('El catálogo no responde'),
                default => [],
            }]));
        }

        if (str_contains($url, '/almaws/v1/bibs')) {
            $bibs = [];
            foreach (explode(',', (string) ($query['mms_id'] ?? '')) as $mms) {
                if (self::MMS_CORMEN === $mms && 'brief' === ($query['view'] ?? 'full')) {
                    $bibs[] = ['mms_id' => $mms, 'title' => 'Introduction to algorithms']; // como Alma: la vista breve no trae el MARC
                } elseif (self::MMS_CORMEN === $mms) {
                    $bibs[] = ['mms_id' => $mms, 'title' => 'Introduction to algorithms', 'anies' => [
                        '<?xml version="1.0" encoding="UTF-16"?><record><datafield ind1="0" ind2=" " tag="AVA">'
                        .'<subfield code="b">CENTRAL</subfield><subfield code="q">Biblioteca Central Antofagasta</subfield>'
                        .'<subfield code="d">QA76.6 C662 2009</subfield><subfield code="e">unavailable</subfield></datafield>'
                        .'<datafield ind1="0" ind2=" " tag="AVA"><subfield code="q">Biblioteca Coquimbo</subfield>'
                        .'<subfield code="d">QA76.6 C662</subfield><subfield code="e">available</subfield></datafield></record>',
                    ]];
                }
            }

            return new MockResponse(json_encode(['bib' => $bibs, 'total_record_count' => \count($bibs)]));
        }

        return new MockResponse('', ['http_code' => 404]);
    }
}
