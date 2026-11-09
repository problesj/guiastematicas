<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Departamento;
use App\Form\DepartamentoType;
use App\Repository\DepartamentoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{_locale}/admin/departamentos', requirements: ['_locale' => 'es|en'])]
final class DepartamentoController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'admin_departamentos', methods: ['GET'])]
    public function listado(DepartamentoRepository $departamentos): Response
    {
        return $this->render('admin/departamentos/listado.html.twig', ['departamentos' => $departamentos->ordenados()]);
    }

    #[Route('/nuevo', name: 'admin_departamento_nuevo', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        return $this->formulario($request, new Departamento(), 'departamento.nuevo');
    }

    #[Route('/{id<\d+>}/editar', name: 'admin_departamento_editar', methods: ['GET', 'POST'])]
    public function editar(Request $request, Departamento $departamento): Response
    {
        return $this->formulario($request, $departamento, 'departamento.editar');
    }

    #[Route('/{id<\d+>}/eliminar', name: 'admin_departamento_eliminar', methods: ['POST'])]
    public function eliminar(Request $request, Departamento $departamento): Response
    {
        if ($this->isCsrfTokenValid('eliminar-departamento-'.$departamento->getId(), $request->getPayload()->getString('_token'))) {
            // Las personas del departamento quedan sin departamento (ON DELETE SET NULL).
            foreach ($departamento->getIntegrantes() as $integrante) {
                $integrante->setDepartamento(null);
            }
            $this->em->remove($departamento);
            $this->em->flush();
            $this->addFlash('exito', 'departamento.eliminado');
        }

        return $this->redirectToRoute('admin_departamentos');
    }

    private function formulario(Request $request, Departamento $departamento, string $titulo): Response
    {
        $formulario = $this->createForm(DepartamentoType::class, $departamento);
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            $this->em->persist($departamento);
            $this->em->flush();
            $this->addFlash('exito', 'departamento.guardado');

            return $this->redirectToRoute('admin_departamentos');
        }

        return $this->render('admin/formulario_simple.html.twig', [
            'formulario' => $formulario,
            'titulo' => $titulo,
            'volver' => 'admin_departamentos',
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }
}
