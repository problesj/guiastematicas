<?php

declare(strict_types=1);

namespace App\Controller\Panel;

use App\Controller\ConsultasController;
use App\Correo\AvisosConsulta;
use App\Entity\Consulta;
use App\Entity\Usuario;
use App\Paginacion\Pagina;
use App\Repository\ConsultaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Consultas recibidas desde la ficha pública: cada bibliotecario ve y responde las suyas; los administradores
 * ven todas (para dar continuidad si alguien se ausenta). Las respuestas llegan a la persona por correo.
 */
#[Route('/{_locale}/panel/consultas', requirements: ['_locale' => 'es|en'])]
#[IsGranted('ROLE_COLABORADOR')]
final class ConsultaController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'panel_consultas', methods: ['GET'])]
    public function listado(Request $request, ConsultaRepository $consultas): Response
    {
        $filtro = $request->query->getString('filtro');
        $filtro = \in_array($filtro, ConsultaRepository::FILTROS, true) ? $filtro : 'pendientes';
        $todas = $this->isGranted('ROLE_ADMINISTRADOR') && $request->query->getBoolean('todas');
        [$numero, $porPagina] = Pagina::leerParametros($request);

        return $this->render('panel/consultas/listado.html.twig', [
            'pagina' => $consultas->bandeja($todas ? null : $this->usuario(), $filtro, $numero, $porPagina),
            'filtro' => $filtro,
            'filtros' => ConsultaRepository::FILTROS,
            'todas' => $todas,
        ]);
    }

    #[Route('/{id<\d+>}', name: 'panel_consulta', methods: ['GET', 'POST'])]
    public function ver(Request $request, Consulta $consulta, AvisosConsulta $avisos): Response
    {
        $this->verificarAcceso($consulta);
        $error = null;
        if ($request->isMethod('POST')) {
            $texto = trim($request->getPayload()->getString('texto'));
            if (!$this->isCsrfTokenValid('responder-consulta-'.$consulta->getId(), $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            if ('' === $texto || mb_strlen($texto) > ConsultasController::LARGO_MAXIMO) {
                $error = 'consulta.texto_obligatorio';
            } else {
                $mensaje = $consulta->responde($this->usuario(), $texto);
                if ($request->getPayload()->getBoolean('cerrar')) {
                    $consulta->setCerrada(true);
                }
                $this->em->flush();
                $avisos->respuestaDePersonal($mensaje);
                $this->em->flush();
                $this->addFlash($mensaje->isCorreoEnviado() ? 'exito' : 'error', $mensaje->isCorreoEnviado() ? 'consulta.respondida' : 'consulta.respondida_sin_correo');

                return $this->redirectToRoute('panel_consulta', ['id' => $consulta->getId()]);
            }
        }

        return $this->render('panel/consultas/ver.html.twig', ['consulta' => $consulta, 'error' => $error], new Response(status: null === $error ? 200 : 422));
    }

    #[Route('/{id<\d+>}/estado', name: 'panel_consulta_estado', methods: ['POST'])]
    public function cambiarEstado(Request $request, Consulta $consulta): Response
    {
        $this->verificarAcceso($consulta);
        if ($this->isCsrfTokenValid('estado-consulta-'.$consulta->getId(), $request->getPayload()->getString('_token'))) {
            $consulta->setCerrada(!$consulta->isCerrada());
            $this->em->flush();
            $this->addFlash('exito', $consulta->isCerrada() ? 'consulta.cerrada' : 'consulta.reabierta');
        }

        return $this->redirectToRoute('panel_consulta', ['id' => $consulta->getId()]);
    }

    private function verificarAcceso(Consulta $consulta): void
    {
        if ($consulta->getBibliotecario() !== $this->usuario() && !$this->isGranted('ROLE_ADMINISTRADOR')) {
            throw $this->createAccessDeniedException();
        }
    }

    private function usuario(): Usuario
    {
        $usuario = $this->getUser();
        \assert($usuario instanceof Usuario);

        return $usuario;
    }
}
