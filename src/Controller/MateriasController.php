<?php

declare(strict_types=1);

namespace App\Controller;

use App\Clasificacion\Asjc;
use App\Entity\Materia;
use App\Form\MateriasType;
use App\Repository\MateriaRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Catálogo de materias activas para el selector compacto (buscador que agrega materias). */
final class MateriasController extends AbstractController
{
    #[Route('/{_locale}/materias/catalogo', name: 'materias_catalogo', requirements: ['_locale' => 'es|en'], methods: ['GET'])]
    public function catalogo(string $_locale, MateriaRepository $materias): JsonResponse
    {
        $otro = 'en' === $_locale ? 'es' : 'en';
        $campos = array_map(fn (array $d) => $d['nombre'], Asjc::porCampo($_locale));
        $lista = array_map(fn (Materia $m) => [
            'id' => $m->getId(),
            'etiqueta' => MateriasType::etiqueta($m, $_locale),
            'campo' => $campos[$m->getCampoAsjc()] ?? null,
            // Para buscar también por el nombre en el otro idioma, el código UCN y las categorías de las propias.
            'texto' => trim(implode(' ', [$m->getNombreTraducido($otro), (string) $m->getCodigo(), ...array_map('strval', $m->getAsjc())])),
        ], $materias->porCampo());

        $respuesta = new JsonResponse($lista);
        $respuesta->setPublic();
        $respuesta->setMaxAge(300);

        return $respuesta;
    }
}
