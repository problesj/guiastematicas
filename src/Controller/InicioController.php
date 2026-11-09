<?php

declare(strict_types=1);

namespace App\Controller;

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
    public function inicio(): Response
    {
        return $this->render('inicio/index.html.twig');
    }
}
