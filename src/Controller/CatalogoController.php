<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Bloque;
use App\Enum\TipoBloque;
use App\Repository\MateriaRepository;
use App\Security\GuiaVoter;
use App\Servicio\BloquesGuia;
use App\Servicio\Catalogo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Enlaces al catálogo de la biblioteca: búsqueda en Primo VE y disponibilidad de los libros de un bloque (Alma). */
#[Route('/{_locale}/catalogo', requirements: ['_locale' => 'es|en'])]
final class CatalogoController extends AbstractController
{
    /**
     * Envía la búsqueda del bloque "Buscar en el catálogo" a Primo VE: texto (q), materias (materia[], por slug) y
     * tipo de material (tipo: libros, tesis, articulos o revistas).
     */
    #[Route('', name: 'catalogo', methods: ['GET'])]
    public function buscar(Request $request, BloquesGuia $bloques, MateriaRepository $materias): RedirectResponse
    {
        $texto = mb_substr(trim($request->query->getString('q')), 0, 300);
        $slugs = array_slice(array_filter((array) ($request->query->all()['materia'] ?? []), 'is_string'), 0, 30);
        $nombres = [] === $slugs ? [] : array_map(
            fn ($m) => $m->getNombre(),
            $materias->findBy(['slug' => array_values($slugs), 'activa' => true], ['nombre' => 'ASC']),
        );
        $destino = $bloques->enlacePrimo($texto, $request->getLocale(), $nombres, $request->query->getString('tipo'));

        return $destino ? $this->redirect($destino) : $this->redirectToRoute('guias');
    }

    /**
     * Disponibilidad de los libros de un bloque, por MMS id: {"991...": {"disponible": true, "texto": "..."}}.
     * Solo consulta los libros guardados en el bloque, así nadie puede usar la clave de Alma para otra cosa.
     */
    #[Route('/disponibilidad/{id<\d+>}', name: 'catalogo_disponibilidad', methods: ['GET'])]
    public function disponibilidad(Bloque $bloque, Catalogo $catalogo, TranslatorInterface $traductor): JsonResponse
    {
        $guia = $bloque->getGuia();
        $visible = null !== $guia && (($guia->isPublicada() && $this->isGranted(GuiaVoter::VER, $guia)) || $this->isGranted(GuiaVoter::EDITAR, $guia));
        if (!$visible || TipoBloque::Libros !== $bloque->getTipo()) {
            throw $this->createNotFoundException();
        }

        $estados = [];
        foreach ($catalogo->disponibilidad(array_column((array) $bloque->dato('items', []), 'mms')) as $mms => $ejemplar) {
            if (null === $ejemplar['biblioteca']) {
                continue; // registro sin ejemplares físicos (p. ej. solo electrónico)
            }
            $estados[$mms] = [
                'disponible' => $ejemplar['disponible'],
                'texto' => $traductor->trans($ejemplar['disponible'] ? 'libro.disponible' : 'libro.no_disponible', ['%biblioteca%' => $ejemplar['biblioteca']])
                    .($ejemplar['signatura'] ? ' · '.$ejemplar['signatura'] : ''),
            ];
        }

        $respuesta = new JsonResponse($estados);
        if ($guia->isPublicada() && !$guia->isRestringida()) {
            $respuesta->setPublic()->setMaxAge(600);
        } else {
            $respuesta->setPrivate();
        }

        return $respuesta;
    }
}
