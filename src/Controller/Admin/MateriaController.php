<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Materia;
use App\Form\MateriaType;
use App\Repository\MateriaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{_locale}/admin/materias', requirements: ['_locale' => 'es|en'])]
final class MateriaController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'admin_materias', methods: ['GET'])]
    public function listado(Request $request, MateriaRepository $materias): Response
    {
        // Por omisión: las propias y las categorías ASJC en uso; «todas» muestra también las 334 categorías.
        $todas = $request->query->getBoolean('todas');
        $enUso = array_map(fn (Materia $m) => $m->getId(), $materias->enUso());
        $lista = array_filter($materias->todas(), fn (Materia $m) => $todas || !$m->isBase() || \in_array($m->getId(), $enUso, true));

        return $this->render('admin/materias/listado.html.twig', [
            'materias' => MateriaRepository::ordenarPorCampo(array_values($lista)),
            'todas' => $todas,
            'sin_clasificar' => \count($materias->sinClasificar()),
        ]);
    }

    #[Route('/nuevo', name: 'admin_materia_nuevo', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        return $this->formulario($request, new Materia(), 'materia.nuevo');
    }

    #[Route('/{id<\d+>}/editar', name: 'admin_materia_editar', methods: ['GET', 'POST'])]
    public function editar(Request $request, Materia $materia): Response
    {
        return $this->formulario($request, $materia, 'materia.editar');
    }

    #[Route('/{id<\d+>}/eliminar', name: 'admin_materia_eliminar', methods: ['POST'])]
    public function eliminar(Request $request, Materia $materia): Response
    {
        // Las categorías ASJC son la base del esquema: se pueden desactivar, no eliminar.
        if ($materia->isBase()) {
            $this->addFlash('error', 'materia.base_no_eliminable');

            return $this->redirectToRoute('admin_materias');
        }
        if ($this->isCsrfTokenValid('eliminar-materia-'.$materia->getId(), $request->getPayload()->getString('_token'))) {
            // Se quita la materia de los especialistas; las guías asociadas (fase 4) impedirán eliminarla.
            foreach ($materia->getEspecialistas() as $especialista) {
                $especialista->removeMateria($materia);
            }
            $this->em->remove($materia);
            $this->em->flush();
            $this->addFlash('exito', 'materia.eliminado');
        }

        return $this->redirectToRoute('admin_materias');
    }

    private function formulario(Request $request, Materia $materia, string $titulo): Response
    {
        $formulario = $this->createForm(MateriaType::class, $materia);
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            $this->em->persist($materia);
            $this->em->flush();
            $this->addFlash('exito', 'materia.guardado');

            return $this->redirectToRoute('admin_materias');
        }

        return $this->render('admin/formulario_simple.html.twig', [
            'formulario' => $formulario,
            'titulo' => $titulo,
            'volver' => 'admin_materias',
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }
}
