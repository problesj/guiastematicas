<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Materia;
use App\Form\MateriaType;
use App\Repository\MateriaRepository;
use App\Servicio\HomologadorMaterias;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/{_locale}/admin/materias', requirements: ['_locale' => 'es|en'])]
final class MateriaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $traductor,
    ) {
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

    /**
     * Homologa una materia propia a una categoría ASJC: pasa a la categoría lo que tenía la propia (ver
     * HomologadorMaterias) y la propia se elimina.
     */
    #[Route('/{id<\d+>}/homologar', name: 'admin_materia_homologar', methods: ['GET', 'POST'])]
    public function homologar(Request $request, Materia $materia, MateriaRepository $materias, HomologadorMaterias $homologador): Response
    {
        if ($materia->isBase()) {
            throw $this->createNotFoundException();
        }
        $categorias = array_values(array_filter($materias->porCampo(), fn (Materia $m) => $m->isBase()));
        $elegida = null;
        $error = null;

        if ($request->isMethod('POST')) {
            $id = $request->getPayload()->getString('categoria');
            $elegida = ctype_digit($id) ? $materias->find((int) $id) : null;
            if (!$this->isCsrfTokenValid('homologar-materia-'.$materia->getId(), $request->getPayload()->getString('_token'))) {
                $error = 'materia.homologar_token';
            } elseif (null === $elegida || !$elegida->isBase()) {
                $error = 'materia.homologar_elige';
            } else {
                $nombre = $materia->getNombre();
                $cambios = $homologador->homologar($materia, $elegida);
                $this->addFlash('exito', $this->traductor->trans('materia.homologada', [
                    '%materia%' => $nombre,
                    '%categoria%' => $elegida->getCodigoAsjc().' · '.$elegida->getNombre(),
                    '%recursos%' => $cambios['recursos'],
                    '%guias%' => $cambios['guias'],
                    '%especialistas%' => $cambios['especialistas'],
                ]));

                return $this->redirectToRoute('admin_materias');
            }
        }

        // Propuesta: la primera categoría en que está clasificada.
        $elegida ??= [] !== $materia->getAsjc() ? $materias->findOneBy(['codigoAsjc' => $materia->getAsjc()[0]]) : null;

        return $this->render('admin/materias/homologar.html.twig', [
            'materia' => $materia,
            'categorias' => $categorias,
            'elegida' => $elegida,
            'cuentas' => $homologador->contar($materia),
            'error' => $error,
        ], new Response(status: null !== $error ? 422 : 200));
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
