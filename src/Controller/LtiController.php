<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Guia;
use App\Lti\ErrorLti;
use App\Lti\Lanzamiento;
use App\Lti\LlavesHerramienta;
use App\Lti\Plataforma;
use App\Lti\Verificador;
use App\Lti\Vinculo;
use App\Repository\GuiaRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Herramienta externa LTI 1.3 para Moodle. El docente agrega «Guías temáticas» a su curso, elige la guía (Deep Linking)
 * y los estudiantes la abren desde el curso. Moodle debe tener registrada la herramienta con las direcciones de
 * Administración → Integraciones.
 */
#[Route('/lti')]
final class LtiController extends AbstractController
{
    public function __construct(private readonly Plataforma $plataforma)
    {
    }

    /** Inicio de sesión de terceros (OIDC): Moodle llama aquí y se le devuelve a su página de autorización. */
    #[Route('/login', name: 'lti_login', methods: ['GET', 'POST'])]
    public function login(Request $request, Verificador $verificador): Response
    {
        $parametros = array_merge($request->query->all(), $request->request->all());
        $destino = $verificador->iniciar($parametros, $this->generateUrl('lti_lanzar', [], UrlGeneratorInterface::ABSOLUTE_URL));
        if (null === $destino) {
            return $this->error(new ErrorLti('lti.error.plataforma'), 400);
        }

        return $this->redirect($destino);
    }

    /** Lanzamiento: abre la guía del enlace, o la elección de guía si el docente está agregando la actividad. */
    #[Route('/lanzar', name: 'lti_lanzar', methods: ['POST'])]
    public function lanzar(Request $request, Verificador $verificador, Vinculo $vinculo, GuiaRepository $guias): Response
    {
        try {
            $lanzamiento = $verificador->verificar($request->request->getString('state'), $request->request->getString('id_token'));
        } catch (ErrorLti $error) {
            return $this->error($error, 401);
        }

        if (Lanzamiento::DEEP_LINKING === $lanzamiento->tipo()) {
            $ajustes = $lanzamiento->ajustesVinculo();
            if ('' === (string) ($ajustes['deep_link_return_url'] ?? '') || !\in_array('ltiResourceLink', (array) ($ajustes['accept_types'] ?? []), true)) {
                return $this->error(new ErrorLti('lti.error.tipo'), 400);
            }

            return $this->enMarco($this->render('lti/elegir.html.twig', [
                'vinculo' => $vinculo->guardar($lanzamiento),
                'curso' => $lanzamiento->curso(),
                'guias' => $guias->publicadas(null, ''),
                'idioma' => $lanzamiento->idioma(),
            ]));
        }

        // Enlace a una guía (parámetro personalizado guia=slug) o a las guías de una materia (materia=slug).
        $slug = $lanzamiento->personalizado('guia');
        if (null !== $slug && null !== $guia = $guias->publicadaPorSlug($slug)) {
            return $this->redirectToRoute('guia', ['_locale' => $guia->getIdioma(), 'slug' => $guia->getSlug()], Response::HTTP_SEE_OTHER);
        }
        $materia = $lanzamiento->personalizado('materia');

        return $this->redirectToRoute('guias', ['_locale' => $lanzamiento->idioma()] + (null !== $materia ? ['materia' => $materia] : []), Response::HTTP_SEE_OTHER);
    }

    /** El docente eligió la guía: se devuelve a Moodle el enlace firmado. */
    #[Route('/vincular', name: 'lti_vincular', methods: ['POST'])]
    public function vincular(Request $request, Vinculo $vinculo, GuiaRepository $guias): Response
    {
        $id = $request->request->getString('vinculo');
        $pedido = $vinculo->leer($id);
        if (null === $pedido) {
            return $this->error(new ErrorLti('lti.error.vinculo_vencido'), 400);
        }
        $guia = $guias->publicadaPorSlug($request->request->getString('guia'));
        if (!$guia instanceof Guia) {
            return $this->enMarco($this->render('lti/elegir.html.twig', [
                'vinculo' => $id,
                'curso' => $pedido['curso'],
                'guias' => $guias->publicadas(null, ''),
                'idioma' => $pedido['idioma'],
                'falta_guia' => true,
            ], new Response(status: 422)));
        }
        $vinculo->olvidar($id);

        $jwt = $vinculo->respuesta($pedido, [[
            'type' => 'ltiResourceLink',
            'title' => $guia->getTitulo(),
            'text' => (string) $guia->getDescripcion(),
            'url' => $this->generateUrl('lti_lanzar', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'custom' => ['guia' => $guia->getSlug()],
        ]]);

        return $this->enMarco($this->render('lti/enviar.html.twig', ['retorno' => $pedido['retorno'], 'jwt' => $jwt, 'guia' => $guia, 'idioma' => $pedido['idioma']]));
    }

    /** Llave pública de la herramienta, para que Moodle verifique las respuestas de vinculación. */
    #[Route('/jwks', name: 'lti_jwks', methods: ['GET'])]
    public function jwks(LlavesHerramienta $llaves): JsonResponse
    {
        $respuesta = new JsonResponse($llaves->jwks());
        $respuesta->setPublic()->setMaxAge(3600);

        return $respuesta;
    }

    private function error(ErrorLti $error, int $estado): Response
    {
        return $this->enMarco($this->render('lti/error.html.twig', ['mensaje' => $error->getMessage()], new Response(status: $estado)));
    }

    /** Moodle muestra estas páginas en un iframe: se permite solo a la plataforma registrada. */
    private function enMarco(Response $respuesta): Response
    {
        $origen = $this->plataforma->origen();
        $respuesta->headers->set('Content-Security-Policy', 'frame-ancestors '.($origen ?? "'self'"));
        $respuesta->headers->remove('X-Frame-Options');
        $respuesta->headers->set('X-Robots-Tag', 'noindex');
        $respuesta->setPrivate();
        $respuesta->headers->addCacheControlDirective('no-store');

        return $respuesta;
    }
}
