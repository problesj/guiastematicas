<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\GuiaRepository;
use App\Repository\MateriaRepository;
use App\Servicio\BloquesGuia;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Guías temáticas publicadas: lista por materia y página de cada guía. */
final class GuiasController extends AbstractController
{
    #[Route('/{_locale}/guias', name: 'guias', requirements: ['_locale' => 'es|en'], methods: ['GET'])]
    public function listado(Request $request, GuiaRepository $guias, MateriaRepository $materias): Response
    {
        $texto = mb_substr(trim($request->query->getString('q')), 0, 100);
        $materia = '' !== $request->query->getString('materia')
            ? $materias->findOneBy(['slug' => $request->query->getString('materia'), 'activa' => true])
            : null;

        return $this->render('guias/listado.html.twig', [
            'guias' => $guias->publicadas($materia, $texto),
            'materias' => $materias->activas(),
            'filtro' => ['materia' => $materia, 'q' => $texto],
        ]);
    }

    #[Route('/{_locale}/guias/{slug}/{pestana}', name: 'guia', requirements: ['_locale' => 'es|en', 'slug' => '[a-z0-9-]+', 'pestana' => '[a-z0-9-]+'], defaults: ['pestana' => null], methods: ['GET'])]
    public function ver(Request $request, string $slug, ?string $pestana, GuiaRepository $guias): Response
    {
        $guia = $guias->publicadaPorSlug($slug) ?? throw $this->createNotFoundException();
        $elegida = $guia->getPestana($pestana);
        if (null === $elegida && null !== $pestana) {
            throw $this->createNotFoundException();
        }
        if (null !== $elegida?->getEnlace()) { // pestaña que es solo un enlace externo
            return $this->redirect($elegida->getEnlace());
        }
        if (null === $pestana) {
            $guias->registrarVisita($guia);
        }

        // El selector ES/EN lleva a la versión de la guía en ese idioma, si existe y está publicada.
        $enlacesIdioma = [];
        $traduccion = $guia->getTraduccion();
        if (null !== $traduccion && $traduccion->isPublicada()) {
            $enlacesIdioma[$traduccion->getIdioma()] = $this->generateUrl('guia', ['_locale' => $traduccion->getIdioma(), 'slug' => $traduccion->getSlug()]);
            $enlacesIdioma[$guia->getIdioma()] = $this->generateUrl('guia', ['_locale' => $guia->getIdioma(), 'slug' => $guia->getSlug()]);
        }

        return $this->render('guias/ver.html.twig', [
            'guia' => $guia,
            'pestana' => $elegida,
            'vista_previa' => false,
            'enlaces_idioma' => $enlacesIdioma,
        ]);
    }

    /** Envía la búsqueda del bloque "Buscar en el catálogo" a Primo VE. */
    #[Route('/{_locale}/catalogo', name: 'catalogo', requirements: ['_locale' => 'es|en'], methods: ['GET'])]
    public function catalogo(Request $request, BloquesGuia $bloques): RedirectResponse
    {
        $texto = mb_substr(trim($request->query->getString('q')), 0, 300);
        $destino = '' === $texto ? null : $bloques->enlacePrimo($texto, $request->getLocale());

        return $destino ? $this->redirect($destino) : $this->redirectToRoute('guias');
    }
}
