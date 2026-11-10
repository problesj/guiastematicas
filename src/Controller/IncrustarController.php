<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Bloque;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Un bloque de una guía pública, solo, para mostrarlo dentro de otra web (Moodle, sitio de la carrera...) con un
 * iframe o con /incrustar.js. Las guías restringidas o sin publicar no se pueden incrustar.
 */
final class IncrustarController extends AbstractController
{
    #[Route('/{_locale}/incrustar/bloque/{id<\d+>}', name: 'incrustar_bloque', requirements: ['_locale' => 'es|en'], methods: ['GET'])]
    public function bloque(Bloque $bloque): Response
    {
        $guia = $bloque->getGuia();
        if (null === $guia || !$guia->isPublicada() || $guia->isRestringida()) {
            throw $this->createNotFoundException();
        }

        $respuesta = $this->render('incrustar/bloque.html.twig', ['bloque' => $bloque, 'guia' => $guia, 'vista_previa' => false]);
        // Cualquier sitio puede mostrarlo en un iframe (esto prevalece sobre X-Frame-Options del servidor).
        $respuesta->headers->set('Content-Security-Policy', 'frame-ancestors *');
        $respuesta->headers->remove('X-Frame-Options');
        $respuesta->headers->set('X-Robots-Tag', 'noindex');
        $respuesta->setPublic()->setMaxAge(300);

        return $respuesta;
    }
}
