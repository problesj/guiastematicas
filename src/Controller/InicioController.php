<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Usuario;
use App\Repository\GuiaRepository;
use App\Repository\RecursoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InicioController extends AbstractController
{
    /**
     * Redirige la raíz del sitio al idioma preferido del navegador (es o en).
     */
    #[Route('/', name: 'raiz', methods: ['GET'])]
    public function raiz(Request $request): RedirectResponse
    {
        $idioma = $request->getPreferredLanguage(['es', 'en']) ?? 'es';

        return $this->redirectToRoute('inicio', ['_locale' => $idioma]);
    }

    #[Route('/{_locale}', name: 'inicio', requirements: ['_locale' => 'es|en'], methods: ['GET'])]
    public function inicio(RecursoRepository $recursos): Response
    {
        return $this->render('inicio/index.html.twig', ['recientes' => $recursos->recientes(10)]);
    }

    /** Búsqueda en el sitio: guías publicadas y recursos de la lista A-Z que coinciden con el texto. */
    #[Route('/{_locale}/buscar', name: 'buscar', requirements: ['_locale' => 'es|en'], methods: ['GET'])]
    public function buscar(Request $request, GuiaRepository $guias, RecursoRepository $recursos): Response
    {
        $texto = mb_substr(trim($request->query->getString('q')), 0, 100);
        $usuario = $this->getUser();

        return $this->render('inicio/buscar.html.twig', [
            'texto' => $texto,
            'guias' => '' === $texto ? [] : \array_slice($guias->publicadas(null, $texto, $usuario instanceof Usuario ? $usuario : null, $this->isGranted('ROLE_ADMINISTRADOR')), 0, 20),
            'recursos' => '' === $texto ? null : $recursos->buscarPublicos(1, 20, null, $texto),
        ]);
    }
}
