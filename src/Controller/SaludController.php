<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Chequeo de disponibilidad para la monitorización externa.
 * Responde 200 si la aplicación y la base de datos funcionan, y 503 si la base de datos no responde.
 */
final class SaludController
{
    public function __construct(private readonly Connection $conexion)
    {
    }

    #[Route('/salud', name: 'salud', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->conexion->executeQuery('SELECT 1');
            $baseDeDatos = 'ok';
        } catch (\Throwable) {
            $baseDeDatos = 'error';
        }

        $ok = 'ok' === $baseDeDatos;

        return new JsonResponse(
            ['estado' => $ok ? 'ok' : 'degradado', 'base_de_datos' => $baseDeDatos],
            $ok ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
