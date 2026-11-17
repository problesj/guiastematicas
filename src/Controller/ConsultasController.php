<?php

declare(strict_types=1);

namespace App\Controller;

use App\Correo\AvisosConsulta;
use App\Entity\Consulta;
use App\Repository\ConsultaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Conversación de una consulta vista por quien la hizo, con el enlace privado que recibió por correo.
 * Desde aquí puede seguir escribiendo mientras el bibliotecario no la cierre.
 */
final class ConsultasController extends AbstractController
{
    public const int LARGO_MAXIMO = 5000;

    #[Route('/{_locale}/consulta/{token}', name: 'consulta_ver', requirements: ['_locale' => 'es|en', 'token' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function ver(
        string $token,
        Request $request,
        ConsultaRepository $consultas,
        EntityManagerInterface $em,
        AvisosConsulta $avisos,
        #[Target('contacto')] RateLimiterFactoryInterface $limitador,
    ): Response {
        $consulta = $consultas->findOneBy(['token' => $token]);
        if (!$consulta instanceof Consulta) {
            throw $this->createNotFoundException();
        }

        $error = null;
        if ($request->isMethod('POST') && !$consulta->isCerrada()) {
            $texto = trim($request->getPayload()->getString('texto'));
            if (!$this->isCsrfTokenValid('consulta-'.$consulta->getId(), $request->getPayload()->getString('_token'))) {
                $error = 'consulta.sesion_vencida';
            } elseif ('' === $texto || mb_strlen($texto) > self::LARGO_MAXIMO) {
                $error = 'consulta.texto_obligatorio';
            } elseif (!$limitador->create($request->getClientIp() ?? 'desconocida')->consume()->isAccepted()) {
                $error = 'consulta.demasiados';
            } else {
                $mensaje = $consulta->escribePersona($texto);
                $em->flush();
                $avisos->respuestaDePersona($mensaje);
                $em->flush();
                $this->addFlash('exito', 'consulta.respuesta_enviada');

                return $this->redirectToRoute('consulta_ver', ['token' => $token]);
            }
        }

        $respuesta = $this->render('consultas/ver.html.twig', ['consulta' => $consulta, 'error' => $error], new Response(status: null === $error ? 200 : 422));
        // Enlace privado: no indexar ni filtrar el token a otros sitios.
        $respuesta->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $respuesta->headers->set('Referrer-Policy', 'no-referrer');

        return $respuesta;
    }
}
