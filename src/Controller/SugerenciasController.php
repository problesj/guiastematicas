<?php

declare(strict_types=1);

namespace App\Controller;

use App\Captcha\Captcha;
use App\Entity\Sugerencia;
use App\Enum\TipoRecurso;
use App\Form\SugerenciaType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Formulario público «Sugerir un recurso»: protegido con captcha propio y límite de envíos por IP. */
final class SugerenciasController extends AbstractController
{
    #[Route('/{_locale}/recursos-bibliograficos/sugerir', name: 'recursos_sugerir', requirements: ['_locale' => 'es|en'], methods: ['GET', 'POST'])]
    public function sugerir(
        Request $request,
        Captcha $captcha,
        EntityManagerInterface $em,
        TranslatorInterface $traductor,
        #[Target('sugerencias')] RateLimiterFactoryInterface $limitador,
    ): Response {
        $sugerencia = (new Sugerencia())->setIdioma($request->getLocale());
        $tipo = TipoRecurso::tryFrom($request->query->getString('tipo'));
        if (null !== $tipo) {
            $sugerencia->setTipo($tipo);
        }

        $formulario = $this->createForm(SugerenciaType::class, $sugerencia, ['idioma' => $request->getLocale()]);
        $formulario->handleRequest($request);
        $estado = 200;

        if ($formulario->isSubmitted()) {
            $estado = 422;
            if (!$limitador->create($request->getClientIp() ?? 'desconocida')->consume()->isAccepted()) {
                $formulario->addError(new FormError($traductor->trans('sugerencia.demasiadas')));
                $estado = 429;
            } elseif (!$captcha->verificar($formulario->get('captcha')->getData())) {
                $formulario->addError(new FormError($traductor->trans('sugerencia.captcha_fallido')));
            } elseif ($formulario->isValid()) {
                $em->persist($sugerencia);
                $em->flush();
                $this->addFlash('exito', 'sugerencia.recibida');

                return $this->redirectToRoute('recursos_sugerir');
            }
        }

        return $this->render('recursos/sugerir.html.twig', [
            'formulario' => $formulario,
            'desafio' => $captcha->desafio(),
        ], new Response(status: $estado));
    }
}
