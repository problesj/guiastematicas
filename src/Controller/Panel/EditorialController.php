<?php

declare(strict_types=1);

namespace App\Controller\Panel;

use App\Entity\Editorial;
use App\Form\EditorialType;
use App\Repository\EditorialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Editoriales de los recursos bibliográficos: editores y administradores. */
#[Route('/{_locale}/panel/editoriales', requirements: ['_locale' => 'es|en'])]
#[IsGranted('ROLE_EDITOR')]
final class EditorialController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'panel_editoriales', methods: ['GET'])]
    public function listado(Request $request, EditorialRepository $editoriales): Response
    {
        $texto = mb_substr(trim($request->query->getString('q')), 0, 100);

        return $this->render('panel/editoriales/listado.html.twig', [
            'editoriales' => $editoriales->listado($texto),
            'q' => $texto,
        ]);
    }

    #[Route('/nueva', name: 'panel_editorial_nueva', methods: ['GET', 'POST'])]
    public function nueva(Request $request): Response
    {
        return $this->formulario($request, new Editorial(), 'editorial.nueva');
    }

    #[Route('/{id<\d+>}/editar', name: 'panel_editorial_editar', methods: ['GET', 'POST'])]
    public function editar(Request $request, Editorial $editorial): Response
    {
        return $this->formulario($request, $editorial, 'editorial.editar');
    }

    #[Route('/{id<\d+>}/eliminar', name: 'panel_editorial_eliminar', methods: ['POST'])]
    public function eliminar(Request $request, Editorial $editorial): Response
    {
        if ($this->isCsrfTokenValid('eliminar-editorial-'.$editorial->getId(), $request->getPayload()->getString('_token'))) {
            // Los recursos de esta editorial quedan sin editorial (ON DELETE SET NULL).
            $this->em->createQuery('UPDATE App\Entity\Recurso r SET r.editorial = NULL WHERE r.editorial = :e')
                ->setParameter('e', $editorial)->execute();
            $this->em->remove($editorial);
            $this->em->flush();
            $this->addFlash('exito', 'editorial.eliminada');
        }

        return $this->redirectToRoute('panel_editoriales');
    }

    private function formulario(Request $request, Editorial $editorial, string $titulo): Response
    {
        $formulario = $this->createForm(EditorialType::class, $editorial);
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            $this->em->persist($editorial);
            $this->em->flush();
            $this->addFlash('exito', 'editorial.guardada');

            return $this->redirectToRoute('panel_editoriales');
        }

        return $this->render('admin/formulario_simple.html.twig', [
            'formulario' => $formulario,
            'titulo' => $titulo,
            'volver' => 'panel_editoriales',
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }
}
