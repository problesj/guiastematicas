<?php

declare(strict_types=1);

namespace App\Controller\Panel;

use App\Entity\Autor;
use App\Form\AutorType;
use App\Repository\AutorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Autores de los recursos bibliográficos: editores y administradores. */
#[Route('/{_locale}/panel/autores', requirements: ['_locale' => 'es|en'])]
#[IsGranted('ROLE_EDITOR')]
final class AutorController extends AbstractController
{
    private const int LIMITE_LISTADO = 500;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'panel_autores', methods: ['GET'])]
    public function listado(Request $request, AutorRepository $autores): Response
    {
        $texto = mb_substr(trim($request->query->getString('q')), 0, 100);

        return $this->render('panel/autores/listado.html.twig', [
            'autores' => $autores->listado($texto, self::LIMITE_LISTADO),
            'limite' => self::LIMITE_LISTADO,
            'q' => $texto,
        ]);
    }

    /** Sugerencias para el selector de autores del formulario de recursos. */
    #[Route('/buscar', name: 'panel_autores_buscar', methods: ['GET'])]
    public function buscar(Request $request, AutorRepository $autores): JsonResponse
    {
        $texto = mb_substr(trim($request->query->getString('q')), 0, 100);
        if (mb_strlen($texto) < 2) {
            return $this->json([]);
        }

        return $this->json(array_map(fn (Autor $a) => [
            'id' => $a->getId(),
            'nombre' => $a->getNombreCompleto(),
            'orcid' => $a->getOrcid(),
        ], $autores->buscar($texto)));
    }

    #[Route('/nuevo', name: 'panel_autor_nuevo', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        return $this->formulario($request, new Autor(), 'autor.nuevo');
    }

    #[Route('/{id<\d+>}/editar', name: 'panel_autor_editar', methods: ['GET', 'POST'])]
    public function editar(Request $request, Autor $autor): Response
    {
        return $this->formulario($request, $autor, 'autor.editar');
    }

    #[Route('/{id<\d+>}/eliminar', name: 'panel_autor_eliminar', methods: ['POST'])]
    public function eliminar(Request $request, Autor $autor): Response
    {
        if ($this->isCsrfTokenValid('eliminar-autor-'.$autor->getId(), $request->getPayload()->getString('_token'))) {
            // Se quita de los recursos donde aparecía.
            $this->em->createQuery('DELETE App\Entity\RecursoAutor ra WHERE ra.autor = :autor')
                ->setParameter('autor', $autor)->execute();
            $this->em->remove($autor);
            $this->em->flush();
            $this->addFlash('exito', 'autor.eliminado');
        }

        return $this->redirectToRoute('panel_autores');
    }

    private function formulario(Request $request, Autor $autor, string $titulo): Response
    {
        $formulario = $this->createForm(AutorType::class, $autor);
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            $this->em->persist($autor);
            $this->em->flush();
            $this->addFlash('exito', 'autor.guardado');

            return $this->redirectToRoute('panel_autores');
        }

        return $this->render('admin/formulario_simple.html.twig', [
            'formulario' => $formulario,
            'titulo' => $titulo,
            'volver' => 'panel_autores',
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }
}
