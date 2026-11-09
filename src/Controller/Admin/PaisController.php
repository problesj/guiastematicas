<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Pais;
use App\Form\PaisType;
use App\Repository\PaisRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Tabla de países (ISO 3166-1) usada por autores y editoriales. */
#[Route('/{_locale}/admin/paises', requirements: ['_locale' => 'es|en'])]
final class PaisController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'admin_paises', methods: ['GET'])]
    public function listado(PaisRepository $paises): Response
    {
        return $this->render('admin/paises/listado.html.twig', ['paises' => $paises->ordenados()]);
    }

    #[Route('/nuevo', name: 'admin_pais_nuevo', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        return $this->formulario($request, new Pais(), 'pais.nuevo');
    }

    #[Route('/{id<\d+>}/editar', name: 'admin_pais_editar', methods: ['GET', 'POST'])]
    public function editar(Request $request, Pais $pais): Response
    {
        return $this->formulario($request, $pais, 'pais.editar');
    }

    #[Route('/{id<\d+>}/eliminar', name: 'admin_pais_eliminar', methods: ['POST'])]
    public function eliminar(Request $request, Pais $pais): Response
    {
        if ($this->isCsrfTokenValid('eliminar-pais-'.$pais->getId(), $request->getPayload()->getString('_token'))) {
            // Autores y editoriales de este país quedan sin país (ON DELETE SET NULL).
            foreach (['Autor', 'Editorial'] as $entidad) {
                $this->em->createQuery("UPDATE App\\Entity\\$entidad x SET x.pais = NULL WHERE x.pais = :p")
                    ->setParameter('p', $pais)->execute();
            }
            $this->em->remove($pais);
            $this->em->flush();
            $this->addFlash('exito', 'pais.eliminado');
        }

        return $this->redirectToRoute('admin_paises');
    }

    /** Vuelve a agregar los países del catálogo ISO que se hayan borrado. */
    #[Route('/completar', name: 'admin_paises_completar', methods: ['POST'])]
    public function completar(Request $request, PaisRepository $paises, TranslatorInterface $traductor): Response
    {
        if ($this->isCsrfTokenValid('completar-paises', $request->getPayload()->getString('_token'))) {
            $this->addFlash('exito', $traductor->trans('pais.completados', ['%count%' => $paises->completarCatalogo()]));
        }

        return $this->redirectToRoute('admin_paises');
    }

    private function formulario(Request $request, Pais $pais, string $titulo): Response
    {
        $formulario = $this->createForm(PaisType::class, $pais);
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            $this->em->persist($pais);
            $this->em->flush();
            $this->addFlash('exito', 'pais.guardado');

            return $this->redirectToRoute('admin_paises');
        }

        return $this->render('admin/formulario_simple.html.twig', [
            'formulario' => $formulario,
            'titulo' => $titulo,
            'volver' => 'admin_paises',
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }
}
