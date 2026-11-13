<?php

declare(strict_types=1);

namespace App\Controller;

use App\Configuracion\Apariencia;
use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Paginacion\Pagina;
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
        return $this->mostrar($request, $recursos, $materias, null, 'lista');
    }

    /**
     * Acceso propio a un solo tipo (bases de datos, libros o revistas), si está habilitado en Administración →
     * Apariencia: la misma lista A-Z sin el filtro de tipo, en lista o cuadrícula.
     */
    #[Route('/{_locale}/recursos-bibliograficos/{vista}', name: 'recursos_vista', requirements: ['_locale' => 'es|en', 'vista' => 'bases-de-datos|libros|revistas'], methods: ['GET'])]
    public function vista(string $vista, Request $request, RecursoRepository $recursos, MateriaRepository $materias, Apariencia $apariencia): Response
    {
        $tipo = array_search($vista, array_map(fn (array $a) => $a[1], Apariencia::ACCESOS_RECURSOS), true);
        $presentacion = false !== $tipo ? $apariencia->vistaRecursos($tipo) : null;
        if (null === $presentacion) {
            throw $this->createNotFoundException();
        }

        return $this->mostrar($request, $recursos, $materias, TipoRecurso::from($tipo), $presentacion, $vista);
    }

    private function mostrar(Request $request, RecursoRepository $recursos, MateriaRepository $materias, ?TipoRecurso $tipoFijo, string $presentacion, ?string $vista = null): Response
    {
        $consulta = $request->query;
        $letra = strtoupper($consulta->getString('letra'));
        $letra = 1 === preg_match('/^[A-Z#]$/', $letra) ? $letra : null;
        $texto = mb_substr(trim($consulta->getString('q')), 0, 100);
        $materia = '' !== $consulta->getString('materia')
            ? $materias->findOneBy(['slug' => $consulta->getString('materia'), 'activa' => true])
            : null;
        $tipo = $tipoFijo ?? TipoRecurso::tryFrom($consulta->getString('tipo'));
        $acceso = TipoAcceso::tryFrom($consulta->getString('acceso'));

        [$numero, $porPagina] = Pagina::leerParametros($request);
        $pagina = $recursos->buscarPublicos($numero, $porPagina, $letra, $texto, $materia, $tipo, $acceso);

        // Agrupados por letra para la vista sin filtro de letra.
        $grupos = [];
        foreach ($pagina->elementos as $recurso) {
            $grupos[$recurso->getLetra()][] = $recurso;
        }

        return $this->render('recursos/listado.html.twig', [
            'grupos' => $grupos,
            'pagina' => $pagina,
            'letras' => $recursos->letrasConRecursos($tipoFijo),
            'materias' => $materias->activas(),
            'tipos' => TipoRecurso::cases(),
            'accesos' => TipoAcceso::cases(),
            'filtro' => [
                'letra' => $letra,
                'q' => $texto,
                'materia' => $materia,
                'tipo' => $tipoFijo ? null : $tipo,
                'acceso' => $acceso,
            ],
            'hay_filtros' => null !== $letra || '' !== $texto || null !== $materia || (null === $tipoFijo && null !== $tipo) || null !== $acceso,
            'tipo_fijo' => $tipoFijo,
            'presentacion' => $presentacion,
            'ruta' => null !== $vista ? 'recursos_vista' : 'recursos',
            'ruta_parametros' => null !== $vista ? ['vista' => $vista] : [],
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
    public function direccionAntigua(Request $request, Apariencia $apariencia): RedirectResponse
    {
        // Si el acceso a Bases de datos está habilitado, lleva a esa vista.
        if (null !== $apariencia->vistaRecursos('base_datos')) {
            return $this->redirectToRoute('recursos_vista', ['vista' => 'bases-de-datos'] + $request->query->all(), Response::HTTP_FOUND);
        }

        return $this->redirectToRoute('recursos', $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }
}
