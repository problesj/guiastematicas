<?php

/*
 * Escribe en JSON el esquema de la base de datos tal como lo definen las entidades de Doctrine (tablas, columnas,
 * relaciones e índices). Lo usa scripts/generar-diagrama-er.py para el diagrama entidad-relación y el diccionario
 * de datos de docs/tecnico. No se conecta a la base de datos.
 *
 * Uso: php scripts/esquema-bd.php > esquema.json
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

$raiz = dirname(__DIR__);
require $raiz.'/vendor/autoload.php';
(new Dotenv())->bootEnv($raiz.'/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$corto = static fn (string $clase): string => (new ReflectionClass($clase))->getShortName();

$tablas = [];
foreach ($em->getMetadataFactory()->getAllMetadata() as $m) {
    $tabla = [
        'clase' => $corto($m->getName()),
        'tabla' => $m->getTableName(),
        'campos' => [],
        'rel' => [],
        'unicos' => $m->table['uniqueConstraints'] ?? [],
        'indices' => $m->table['indexes'] ?? [],
    ];
    foreach ($m->fieldMappings as $fm) {
        $fm = (array) $fm;
        $tabla['campos'][] = [
            'columna' => $fm['columnName'],
            'tipo' => $fm['type'],
            'pk' => !empty($fm['id']),
            'nulo' => !empty($fm['nullable']),
            'largo' => $fm['length'] ?? null,
            'unico' => !empty($fm['unique']),
            'enum' => isset($fm['enumType']) ? array_map(static fn ($c) => $c->value, $fm['enumType']::cases()) : null,
        ];
    }
    foreach ($m->associationMappings as $campo => $am) {
        $am = (array) $am;
        $rel = ['campo' => $campo, 'destino' => $corto($am['targetEntity'])];
        if (!empty($am['joinColumns'])) {
            $jc = (array) $am['joinColumns'][0];
            $rel += ['columna' => $jc['name'], 'nulo' => $jc['nullable'] ?? true, 'onDelete' => $jc['onDelete'] ?? null];
        }
        if (!empty($am['joinTable'])) {
            $jt = (array) $am['joinTable'];
            $rel += [
                'tablaIntermedia' => $jt['name'],
                'columna' => ((array) $jt['joinColumns'][0])['name'],
                'columnaDestino' => ((array) $jt['inverseJoinColumns'][0])['name'],
            ];
        }
        if (isset($rel['columna'])) {
            $tabla['rel'][] = $rel;
        }
    }
    $tablas[] = $tabla;
}

echo json_encode($tablas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
