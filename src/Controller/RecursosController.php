<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Repository\MateriaRepository;
use App\Repository\RecursoRepository;
use App\Servicio\ProxyAccesum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Lista pública A-Z de recursos bibliográficos electrónicos (bases de datos, revistas, libros, normas, etc.). */
final class RecursosController extends AbstractController
{
    #[Route('/{_locale}/recursos-bibliograficos', name: 'recursos', requirements: ['_locale' => 'es|en'], methods: ['GET'])]
    public function listado(Request $request, RecursoRepository $recursos, MateriaRepository $materias): Response
    {
        $consulta = $request->query;
        $letra = strtoupper($consulta->getString('letra'));
        $letra = 1 === preg_match('/^[A-Z#]$/', $letra) ? $letra : null;
        $texto = mb_substr(trim($consulta->getString('q')), 0, 100);
        $materia = '' !== $consulta->getString('materia')
            ? $materias->findOneBy(['slug' => $consulta->getString('materia'), 'activa' => true])
            : null;
        $tipo = TipoRecurso::tryFrom($consulta->getString('tipo'));
        $acceso = TipoAcceso::tryFrom($consulta->getString('acceso'));

        $resultados = $recursos->buscarPublicos($letra, $texto, $materia, $tipo, $acceso);

        // Agrupados por letra para la vista sin filtro de letra.
        $grupos = [];
        foreach ($resultados as $recurso) {
            $grupos[$recurso->getLetra()][] = $recurso;
        }

        return $this->render('recursos/listado.html.twig', [
            'grupos' => $grupos,
            'total' => \count($resultados),
            'letras' => $recursos->letrasConRecursos(),
            'materias' => $materias->activas(),
            'tipos' => TipoRecurso::cases(),
            'accesos' => TipoAcceso::cases(),
            'filtro' => [
                'letra' => $letra,
                'q' => $texto,
                'materia' => $materia,
                'tipo' => $tipo,
                'acceso' => $acceso,
            ],
            'hay_filtros' => null !== $letra || '' !== $texto || null !== $materia || null !== $tipo || null !== $acceso,
        ]);
    }

    /** Enlace de salida: suma el clic y redirige a la URL del recurso, por el proxy si corresponde. */
    #[Route('/recursos/{id<\d+>}/ir', name: 'recursos_ir', methods: ['GET'])]
    #[Route('/bases-de-datos/{id<\d+>}/ir', name: 'bases_datos_ir', methods: ['GET'])] // enlaces antiguos
    public function ir(Recurso $recurso, RecursoRepository $recursos, ProxyAccesum $proxy): RedirectResponse
    {
        if (!$recurso->estaDisponible()) {
            throw $this->createNotFoundException();
        }

        $recursos->registrarClic($recurso);

        return new RedirectResponse($proxy->enlace($recurso));
    }

    /** Dirección anterior de la lista ("Bases de datos A-Z"); se conserva para marcadores y enlaces ya publicados. */
    #[Route('/{_locale}/bases-de-datos', name: 'bases_datos', requirements: ['_locale' => 'es|en'], methods: ['GET'])]
    public function direccionAntigua(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('recursos', $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }
}
