<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\GoogleAuthenticator;
use App\Servicio\MetodosAcceso;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SeguridadController extends AbstractController
{
    /** Formulario de acceso. El envío (POST) lo procesa App\Security\AccesoFormularioAuthenticator. */
    #[Route('/{_locale}/acceso', name: 'acceso', requirements: ['_locale' => 'es|en'], methods: ['GET', 'POST'])]
    public function acceso(AuthenticationUtils $utilidades): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('panel');
        }

        return $this->render('seguridad/acceso.html.twig', [
            'error' => $utilidades->getLastAuthenticationError(),
            'ultimo_identificador' => $utilidades->getLastUsername(),
        ]);
    }

    /** Lo intercepta el cortafuegos (logout en security.yaml). */
    #[Route('/salir', name: 'salir', methods: ['GET'])]
    public function salir(): never
    {
        throw new \LogicException('El cortafuegos debe interceptar esta ruta.');
    }

    #[Route('/conectar/google', name: 'google_conectar', methods: ['GET'])]
    public function conectarGoogle(Request $request, ClientRegistry $clientes, MetodosAcceso $metodos): RedirectResponse
    {
        if (!$metodos->google()) {
            throw $this->createNotFoundException();
        }

        $idioma = \in_array($request->query->get('idioma'), ['es', 'en'], true) ? $request->query->get('idioma') : 'es';
        $request->getSession()->set(GoogleAuthenticator::IDIOMA_SESION, $idioma);

        return $clientes->getClient('google')->redirect(['openid', 'email', 'profile'], ['prompt' => 'select_account']);
    }

    /** Lo procesa App\Security\GoogleAuthenticator; solo llega aquí si Google no está configurado. */
    #[Route('/conectar/google/verificar', name: 'google_verificar', methods: ['GET'])]
    public function verificarGoogle(): never
    {
        throw $this->createNotFoundException();
    }
}
