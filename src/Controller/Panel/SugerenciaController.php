<?php

declare(strict_types=1);

namespace App\Controller\Panel;

use App\Entity\Sugerencia;
use App\Entity\Usuario;
use App\Enum\EstadoSugerencia;
use App\Enum\TipoRecurso;
use App\Form\RecursoType;
use App\Paginacion\Pagina;
use App\Repository\EditorialRepository;
use App\Repository\SugerenciaRepository;
use App\Security\SugerenciaVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bandeja de sugerencias del sitio público. Cada persona ve las de las materias de sus guías;
 * al aprobar, revisa y completa los datos y el recurso entra a la lista A-Z. La primera decisión vale.
 */
#[Route('/{_locale}/panel/sugerencias', requirements: ['_locale' => 'es|en'])]
#[IsGranted('ROLE_COLABORADOR')]
final class SugerenciaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SugerenciaRepository $sugerencias,
    ) {
    }

    #[Route('', name: 'panel_sugerencias', methods: ['GET'])]
    public function listado(Request $request): Response
    {
        $estado = EstadoSugerencia::tryFrom($request->query->getString('estado')) ?? EstadoSugerencia::Pendiente;
        [$numero, $porPagina] = Pagina::leerParametros($request);

        return $this->render('panel/sugerencias/listado.html.twig', [
            'pagina' => $this->sugerencias->bandeja($this->revisor(), $estado, $numero, $porPagina),
            'estado' => $estado,
            'estados' => EstadoSugerencia::cases(),
        ]);
    }

    #[Route('/{id<\d+>}', name: 'panel_sugerencia_revisar', methods: ['GET', 'POST'])]
    public function revisar(Request $request, Sugerencia $sugerencia, EditorialRepository $editoriales): Response
    {
        $this->denyAccessUnlessGranted(SugerenciaVoter::REVISAR, $sugerencia);
        $datos = [
            'sugerencia' => $sugerencia,
            'duplicados' => $this->sugerencias->posiblesDuplicados($sugerencia),
        ];
        if (!$sugerencia->isPendiente()) {
            return $this->render('panel/sugerencias/revisar.html.twig', $datos);
        }

        $recurso = $sugerencia->crearRecurso();
        $formulario = $this->createForm(RecursoType::class, $recurso);
        $formulario->handleRequest($request);
        if ($formulario->isSubmitted() && $formulario->isValid()) {
            $this->em->refresh($sugerencia);
            if (!$sugerencia->isPendiente()) {
                $this->addFlash('error', 'sugerencia.ya_revisada');

                return $this->redirectToRoute('panel_sugerencia_revisar', ['id' => $sugerencia->getId()]);
            }
            $this->em->persist($recurso);
            $sugerencia->aprobar($this->usuario(), $recurso);
            $this->em->flush();
            $this->addFlash('exito', 'sugerencia.aprobada');

            return $this->redirectToRoute('panel_sugerencias');
        }

        return $this->render('panel/sugerencias/revisar.html.twig', $datos + [
            'formulario' => $formulario,
            'campos_bibliograficos' => TipoRecurso::CAMPOS_BIBLIOGRAFICOS,
            'editoriales' => $editoriales->nombres(),
            'tipos_por_campo' => array_combine(TipoRecurso::CAMPOS_BIBLIOGRAFICOS, array_map(TipoRecurso::tiposConCampo(...), TipoRecurso::CAMPOS_BIBLIOGRAFICOS)),
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id<\d+>}/rechazar', name: 'panel_sugerencia_rechazar', methods: ['POST'])]
    public function rechazar(Request $request, Sugerencia $sugerencia): Response
    {
        $this->denyAccessUnlessGranted(SugerenciaVoter::REVISAR, $sugerencia);
        if (!$this->isCsrfTokenValid('rechazar-sugerencia-'.$sugerencia->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$sugerencia->isPendiente()) {
            $this->addFlash('error', 'sugerencia.ya_revisada');

            return $this->redirectToRoute('panel_sugerencia_revisar', ['id' => $sugerencia->getId()]);
        }
        $sugerencia->rechazar($this->usuario(), mb_substr($request->getPayload()->getString('motivo'), 0, 2000));
        $this->em->flush();
        $this->addFlash('exito', 'sugerencia.rechazada');

        return $this->redirectToRoute('panel_sugerencias');
    }

    /** Para spam o duplicados que no vale la pena conservar. */
    #[Route('/{id<\d+>}/eliminar', name: 'panel_sugerencia_eliminar', methods: ['POST'])]
    public function eliminar(Request $request, Sugerencia $sugerencia): Response
    {
        $this->denyAccessUnlessGranted(SugerenciaVoter::REVISAR, $sugerencia);
        if ($this->isCsrfTokenValid('eliminar-sugerencia-'.$sugerencia->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($sugerencia);
            $this->em->flush();
            $this->addFlash('exito', 'sugerencia.eliminada');
        }

        return $this->redirectToRoute('panel_sugerencias');
    }

    /** null para administradores: ven todas. */
    private function revisor(): ?Usuario
    {
        return $this->isGranted('ROLE_ADMINISTRADOR') ? null : $this->usuario();
    }

    private function usuario(): Usuario
    {
        $usuario = $this->getUser();
        \assert($usuario instanceof Usuario);

        return $usuario;
    }
}
