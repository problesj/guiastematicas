<?php

declare(strict_types=1);

namespace App\Controller\Panel;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Pestana;
use App\Entity\Recurso;
use App\Entity\Seccion;
use App\Entity\Usuario;
use App\Enum\TipoBloque;
use App\Enum\TipoRecurso;
use App\Form\BloqueType;
use App\Form\GuiaType;
use App\Repository\GuiaRepository;
use App\Repository\MateriaRepository;
use App\Repository\RecursoRepository;
use App\Security\GuiaVoter;
use App\Servicio\AlmacenImagenesGuia;
use App\Servicio\Biblioges;
use App\Servicio\BloquesGuia;
use App\Servicio\Catalogo;
use App\Servicio\DatosLibro;
use App\Servicio\DatosRevista;
use App\Servicio\LibrosEnRecursos;
use App\Servicio\RevistasEnRecursos;
use App\Servicio\MetadatosDoi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Editor de guías del panel. El responsable y los coeditores editan el contenido (pestañas, secciones y bloques);
 * publicar, cambiar los datos o eliminar es del responsable (editor) y de los administradores (ver GuiaVoter).
 */
#[Route('/{_locale}/panel/guias', requirements: ['_locale' => 'es|en'])]
final class GuiaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BloquesGuia $bloques,
        #[Autowire(service: 'html_sanitizer.sanitizer.app.contenido_guia')]
        private readonly HtmlSanitizerInterface $saneador,
        private readonly TranslatorInterface $traductor,
        private readonly AlmacenImagenesGuia $imagenes,
        private readonly LibrosEnRecursos $librosEnRecursos,
        private readonly RevistasEnRecursos $revistasEnRecursos,
        private readonly Catalogo $catalogo,
        private readonly Biblioges $biblioges,
    ) {
    }

    #[Route('', name: 'panel_guias', methods: ['GET'])]
    public function listado(Request $request, GuiaRepository $guias, #[CurrentUser] Usuario $usuario): Response
    {
        $texto = mb_substr(trim($request->query->getString('q')), 0, 100);

        return $this->render('panel/guias/listado.html.twig', [
            'guias' => $guias->editablesPor($usuario, $this->isGranted('ROLE_ADMINISTRADOR'), $texto),
            'q' => $texto,
        ]);
    }

    #[Route('/nueva', name: 'panel_guia_nueva', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function nueva(Request $request, #[CurrentUser] Usuario $usuario): Response
    {
        $guia = (new Guia())->setResponsable($usuario)->setIdioma($request->getLocale());
        $formulario = $this->createForm(GuiaType::class, $guia, ['administrador' => $this->isGranted('ROLE_ADMINISTRADOR')]);
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            // Toda guía nace con una pestaña "Inicio" y una sección vacía, lista para agregar bloques.
            $inicio = new Pestana($this->trans('guia.pestana_inicial', $guia->getIdioma()));
            $guia->agregarPestana($inicio);
            $inicio->agregarSeccion(new Seccion());
            $this->imagenes->aplicarFormulario($formulario, $guia);
            $this->em->persist($guia);
            $this->em->flush();
            $this->addFlash('exito', 'guia.creada');

            return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId()]);
        }

        return $this->render('panel/guias/datos.html.twig', [
            'formulario' => $formulario,
            'guia' => null,
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id<\d+>}/datos', name: 'panel_guia_datos', methods: ['GET', 'POST'])]
    #[IsGranted(GuiaVoter::GESTIONAR, 'guia')]
    public function datos(Request $request, Guia $guia): Response
    {
        $formulario = $this->createForm(GuiaType::class, $guia, ['administrador' => $this->isGranted('ROLE_ADMINISTRADOR')]);
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            $this->imagenes->aplicarFormulario($formulario, $guia);
            $this->em->flush();
            $this->addFlash('exito', 'guia.guardada');

            return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId()]);
        }

        return $this->render('panel/guias/datos.html.twig', [
            'formulario' => $formulario,
            'guia' => $guia,
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }

    /** Página principal del editor: pestañas, secciones y bloques de la pestaña elegida. */
    #[Route('/{id<\d+>}', name: 'panel_guia_editar', methods: ['GET'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function editar(Request $request, Guia $guia): Response
    {
        $pestana = $this->pestanaElegida($guia, $request->query->getInt('pestana'));

        return $this->render('panel/guias/editor.html.twig', [
            'guia' => $guia,
            'pestana' => $pestana,
            'tipos_bloque' => TipoBloque::cases(),
        ]);
    }

    #[Route('/{id<\d+>}/vista-previa', name: 'panel_guia_vista_previa', methods: ['GET'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function vistaPrevia(Request $request, Guia $guia): Response
    {
        $pestana = $this->pestanaElegida($guia, $request->query->getInt('pestana'));

        return $this->render('guias/ver.html.twig', ['guia' => $guia, 'pestana' => $pestana, 'vista_previa' => true]);
    }

    #[Route('/{id<\d+>}/publicar', name: 'panel_guia_publicar', methods: ['POST'])]
    #[IsGranted(GuiaVoter::GESTIONAR, 'guia')]
    public function publicar(Request $request, Guia $guia): Response
    {
        if ($this->tokenValido($request, 'publicar-guia-'.$guia->getId())) {
            $publicar = !$guia->isPublicada();
            $publicar ? $guia->publicar() : $guia->despublicar();
            $this->em->flush();
            $this->addFlash('exito', $publicar ? 'guia.publicada' : 'guia.despublicada');
        }

        return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId()]);
    }

    #[Route('/{id<\d+>}/eliminar', name: 'panel_guia_eliminar', methods: ['POST'])]
    #[IsGranted(GuiaVoter::GESTIONAR, 'guia')]
    public function eliminar(Request $request, Guia $guia): Response
    {
        if ($this->tokenValido($request, 'eliminar-guia-'.$guia->getId())) {
            $guia->setTraduccion(null);
            $this->imagenes->eliminarDe($guia); // antes de borrar: después Doctrine deja la guía sin id
            $this->em->remove($guia);
            $this->em->flush();
            $this->addFlash('exito', 'guia.eliminada');
        }

        return $this->redirectToRoute('panel_guias');
    }

    // ---- Pestañas ----

    #[Route('/{id<\d+>}/pestanas', name: 'panel_guia_pestana_nueva', methods: ['POST'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function nuevaPestana(Request $request, Guia $guia): Response
    {
        $titulo = mb_substr(trim($request->getPayload()->getString('titulo')), 0, 100);
        if (!$this->tokenValido($request, 'editar-guia-'.$guia->getId()) || '' === $titulo) {
            return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId()]);
        }
        $pestana = new Pestana($titulo);
        $guia->agregarPestana($pestana);
        $pestana->agregarSeccion(new Seccion());
        $this->guardar($guia);

        return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId(), 'pestana' => $pestana->getId()]);
    }

    /** Opciones de la pestaña: título, pestaña principal de la que depende, enlace externo y etiqueta "Nuevo". */
    #[Route('/{id<\d+>}/pestanas/{pestana<\d+>}/renombrar', name: 'panel_guia_pestana_renombrar', methods: ['POST'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function renombrarPestana(Request $request, Guia $guia, #[MapEntity(id: 'pestana')] Pestana $pestana): Response
    {
        $this->comprobarPertenencia($guia, $pestana->getGuia());
        $datos = $request->getPayload();
        $titulo = mb_substr(trim($datos->getString('titulo')), 0, 100);
        $enlace = trim($datos->getString('enlace'));
        if (!$this->tokenValido($request, 'editar-guia-'.$guia->getId()) || '' === $titulo) {
            return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId(), 'pestana' => $pestana->getId()]);
        }
        if ('' !== $enlace && !$this->enlaceValido($enlace)) {
            $this->addFlash('error', 'guia.enlace_invalido');

            return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId(), 'pestana' => $pestana->getId()]);
        }

        $padre = null;
        $idPadre = $datos->getString('padre');
        if (ctype_digit($idPadre)) {
            foreach ($guia->getPestanasPrincipales() as $principal) {
                if ($principal->getId() === (int) $idPadre && $principal !== $pestana) {
                    $padre = $principal;
                }
            }
            if (null !== $padre && [] !== $pestana->getSubpestanas()) { // un solo nivel
                $this->addFlash('error', 'guia.subpestana_con_hijas');
                $padre = $pestana->getPadre();
            }
        }

        $pestana->setTitulo($titulo)->setPadre($padre)->setEnlace($enlace)->setNueva($datos->getBoolean('nueva'));
        $this->guardar($guia);

        return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId(), 'pestana' => $pestana->getId()]);
    }

    #[Route('/{id<\d+>}/pestanas/{pestana<\d+>}/eliminar', name: 'panel_guia_pestana_eliminar', methods: ['POST'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function eliminarPestana(Request $request, Guia $guia, #[MapEntity(id: 'pestana')] Pestana $pestana): Response
    {
        $this->comprobarPertenencia($guia, $pestana->getGuia());
        if ($this->tokenValido($request, 'editar-guia-'.$guia->getId())) {
            foreach ($pestana->getSubpestanas() as $hija) { // las subpestañas pasan a ser principales
                $hija->setPadre(null);
            }
            $guia->removePestana($pestana);
            $this->renumerar($guia->getPestanas());
            $this->guardar($guia);
            $this->addFlash('exito', 'guia.pestana_eliminada');
        }

        return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId()]);
    }

    // ---- Secciones ----

    #[Route('/{id<\d+>}/pestanas/{pestana<\d+>}/secciones', name: 'panel_guia_seccion_nueva', methods: ['POST'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function nuevaSeccion(Request $request, Guia $guia, #[MapEntity(id: 'pestana')] Pestana $pestana): Response
    {
        $this->comprobarPertenencia($guia, $pestana->getGuia());
        if ($this->tokenValido($request, 'editar-guia-'.$guia->getId())) {
            $pestana->agregarSeccion(new Seccion(mb_substr($request->getPayload()->getString('titulo'), 0, 150)));
            $this->guardar($guia);
        }

        return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId(), 'pestana' => $pestana->getId()]);
    }

    #[Route('/{id<\d+>}/secciones/{seccion<\d+>}/renombrar', name: 'panel_guia_seccion_renombrar', methods: ['POST'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function renombrarSeccion(Request $request, Guia $guia, #[MapEntity(id: 'seccion')] Seccion $seccion): Response
    {
        $this->comprobarPertenencia($guia, $seccion->getGuia());
        if ($this->tokenValido($request, 'editar-guia-'.$guia->getId())) {
            $seccion->setTitulo(mb_substr($request->getPayload()->getString('titulo'), 0, 150));
            $columnas = $request->getPayload()->getString('columnas');
            if (ctype_digit($columnas)) {
                $seccion->setColumnas((int) $columnas);
            }
            $this->guardar($guia);
        }

        return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId(), 'pestana' => $seccion->getPestana()?->getId(), '_fragment' => 'seccion-'.$seccion->getId()]);
    }

    #[Route('/{id<\d+>}/secciones/{seccion<\d+>}/eliminar', name: 'panel_guia_seccion_eliminar', methods: ['POST'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function eliminarSeccion(Request $request, Guia $guia, #[MapEntity(id: 'seccion')] Seccion $seccion): Response
    {
        $this->comprobarPertenencia($guia, $seccion->getGuia());
        $pestana = $seccion->getPestana();
        if ($this->tokenValido($request, 'editar-guia-'.$guia->getId())) {
            $pestana->removeSeccion($seccion);
            $this->renumerar($pestana->getSecciones());
            $this->guardar($guia);
            $this->addFlash('exito', 'guia.seccion_eliminada');
        }

        return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId(), 'pestana' => $pestana->getId()]);
    }

    // ---- Bloques ----

    #[Route('/{id<\d+>}/secciones/{seccion<\d+>}/bloques/{tipo}', name: 'panel_guia_bloque_nuevo', methods: ['GET', 'POST'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function nuevoBloque(Request $request, Guia $guia, #[MapEntity(id: 'seccion')] Seccion $seccion, TipoBloque $tipo): Response
    {
        $this->comprobarPertenencia($guia, $seccion->getGuia());

        return $this->formularioBloque($request, $guia, new Bloque($tipo), $seccion);
    }

    #[Route('/{id<\d+>}/bloques/{bloque<\d+>}/editar', name: 'panel_guia_bloque_editar', methods: ['GET', 'POST'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function editarBloque(Request $request, Guia $guia, #[MapEntity(id: 'bloque')] Bloque $bloque): Response
    {
        $this->comprobarPertenencia($guia, $bloque->getGuia());

        return $this->formularioBloque($request, $guia, $bloque, $bloque->getSeccion());
    }

    /** Datos de un libro por ISBN o DOI, para el botón «Buscar datos» del bloque Libros (no guarda nada). */
    #[Route('/datos-libro', name: 'panel_guias_datos_libro', methods: ['GET'])]
    public function datosLibro(Request $request, DatosLibro $datos): JsonResponse
    {
        $libro = $datos->buscar(mb_substr($request->query->getString('isbn'), 0, 30), mb_substr($request->query->getString('doi'), 0, 200));

        return null === $libro ? new JsonResponse(['error' => 'no_encontrado'], 404) : new JsonResponse($libro);
    }

    /** Libros del catálogo (Primo VE) por título exacto, autor y editorial, para elegir cuáles agregar al bloque Libros. */
    #[Route('/buscar-libros/catalogo', name: 'panel_guias_buscar_libros_catalogo', methods: ['GET'])]
    public function buscarLibrosCatalogo(Request $request): JsonResponse
    {
        $porPagina = 20;
        $pagina = max(1, min(50, $request->query->getInt('pagina', 1)));
        $resultado = $this->catalogo->buscarLibros(
            $request->query->getString('titulo'),
            $request->query->getString('autor'),
            $request->query->getString('editorial'),
            ($pagina - 1) * $porPagina,
            $porPagina,
        );
        if (null === $resultado) {
            $sinCriterios = '' === trim($request->query->getString('titulo').$request->query->getString('autor').$request->query->getString('editorial'));

            return new JsonResponse(['error' => $sinCriterios ? 'parametros' : 'sin_respuesta'], $sinCriterios ? 400 : 502);
        }
        $idioma = $request->getLocale();

        return new JsonResponse([
            'total' => $resultado['total'],
            'pagina' => $pagina,
            'paginas' => (int) ceil(min($resultado['total'], 1000) / $porPagina),
            // Sin ISBN el registro no se puede recalcular al guardar: el libro enlaza a su registro en el catálogo.
            'libros' => array_map(fn (array $l) => $l + ['enlace' => null === $l['isbn'] ? $this->catalogo->enlaceRegistro($l['registro'], $idioma) : null], $resultado['libros']),
        ]);
    }

    /** Datos de una revista por ISSN, para el botón «Buscar datos» del bloque Revistas (no guarda nada). */
    #[Route('/datos-revista', name: 'panel_guias_datos_revista', methods: ['GET'])]
    public function datosRevista(Request $request, DatosRevista $datos): JsonResponse
    {
        $revista = $datos->buscar(mb_substr($request->query->getString('issn'), 0, 20));

        return null === $revista ? new JsonResponse(['error' => 'no_encontrado'], 404) : new JsonResponse($revista);
    }

    /**
     * Revistas del catálogo (búsqueda de revistas de Primo VE) por palabras del título y/o una categoría, para elegir
     * cuáles agregar al bloque Revistas. La categoría puede venir sola o dentro de un enlace copiado de la búsqueda de
     * revistas de Primo (parámetro "journals=category,…"; si el enlace trae también palabras de búsqueda, se usan).
     */
    #[Route('/buscar-revistas/catalogo', name: 'panel_guias_buscar_revistas_catalogo', methods: ['GET'])]
    public function buscarRevistasCatalogo(Request $request): JsonResponse
    {
        $porPagina = 20;
        $pagina = max(1, min(50, $request->query->getInt('pagina', 1)));
        [$texto, $categoria] = self::criteriosRevistas(
            mb_substr($request->query->getString('texto'), 0, 200),
            mb_substr($request->query->getString('categoria'), 0, 1000),
        );
        $resultado = null === $texto && null === $categoria ? null
            : $this->catalogo->buscarRevistas($texto, $categoria, ($pagina - 1) * $porPagina, $porPagina);
        if (null === $resultado) {
            $sinCriterios = null === $texto && null === Catalogo::categoriaRevistas($categoria);

            // El detalle (código HTTP y mensaje de Primo) ayuda a saber si falla la clave, la vista o la consulta.
            return new JsonResponse($sinCriterios ? ['error' => 'parametros'] : ['error' => 'sin_respuesta', 'detalle' => $this->catalogo->errorRevistas()], $sinCriterios ? 400 : 502);
        }
        $idioma = $request->getLocale();

        return new JsonResponse([
            'total' => $resultado['total'],
            'pagina' => $pagina,
            'paginas' => (int) ceil(min($resultado['total'], 2000) / $porPagina),
            // Sin ISSN el registro no se puede recalcular al guardar: la revista enlaza a su registro en el catálogo.
            'revistas' => array_map(fn (array $r) => $r + ['enlace' => null === $r['issn'] ? $this->catalogo->enlaceRegistro($r['registro'], $idioma) : null], $resultado['revistas']),
        ]);
    }

    /** Árbol de categorías de revistas de Primo (áreas, subáreas y especialidades) para el buscador del bloque Revistas. */
    #[Route('/buscar-revistas/categorias', name: 'panel_guias_buscar_revistas_categorias', methods: ['GET'])]
    public function categoriasRevistas(Request $request): JsonResponse
    {
        $arbol = $this->catalogo->categoriasRevistas($request->getLocale());

        return null === $arbol ? new JsonResponse(['error' => 'sin_respuesta'], 502) : new JsonResponse(['categorias' => $arbol]);
    }

    /**
     * Palabras del título y categoría de revistas. La categoría puede ser un enlace de la búsqueda de revistas de Primo
     * (…/discovery/jsearch?query=…&journals=category,Health_Biological_Sciences─Human_Anatomy_Physiology…).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function criteriosRevistas(string $texto, string $categoria): array
    {
        $texto = trim($texto);
        $categoria = trim($categoria);
        if (str_contains($categoria, '://') || str_contains($categoria, 'journals=')) {
            parse_str((string) parse_url(str_contains($categoria, '?') ? $categoria : '?'.$categoria, \PHP_URL_QUERY), $parametros);
            $categoria = \is_string($parametros['journals'] ?? null) ? $parametros['journals'] : '';
            // query=contains,dbcategory,<palabras> (o any,contains,<palabras>): el último tramo son las palabras.
            $consulta = \is_string($parametros['query'] ?? null) ? explode(',', $parametros['query'], 3) : [];
            if ('' === $texto && 3 === \count($consulta)) {
                $texto = trim($consulta[2]);
            }
        }
        $categoria = trim((string) preg_replace('/^category,/', '', $categoria));

        return ['' === $texto ? null : $texto, '' === $categoria ? null : $categoria];
    }

    /** Carreras vigentes de Biblioges, para la lista del buscador (se piden al abrirlo, no al cargar el formulario). */
    #[Route('/buscar-libros/carreras', name: 'panel_guias_buscar_libros_carreras', methods: ['GET'])]
    public function carrerasBiblioges(): JsonResponse
    {
        return new JsonResponse(['carreras' => $this->biblioges->carreras()]);
    }

    /** Bibliografías de una carrera o asignatura en Biblioges, para elegir cuáles agregar al bloque Libros. */
    #[Route('/buscar-libros/biblioges', name: 'panel_guias_buscar_libros_biblioges', methods: ['GET'])]
    public function buscarLibrosBiblioges(Request $request): JsonResponse
    {
        $resultado = $this->biblioges->bibliografias(
            $request->query->getString('modo'),
            mb_substr($request->query->getString('codigo'), 0, 40),
            $request->query->getString('tipo', 'todas'),
            $request->query->getString('fuente', 'declaradas'),
            $request->query->getBoolean('ultima'),
        );

        return new JsonResponse($resultado, match ($resultado['error'] ?? null) {
            null => 200,
            'parametros' => 400,
            'no_encontrado' => 404,
            default => 502,
        });
    }

    /** Código para mostrar el bloque en otra web (iframe o /incrustar.js). */
    #[Route('/{id<\d+>}/bloques/{bloque<\d+>}/incrustar', name: 'panel_guia_bloque_incrustar', methods: ['GET'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function incrustarBloque(Guia $guia, #[MapEntity(id: 'bloque')] Bloque $bloque): Response
    {
        $this->comprobarPertenencia($guia, $bloque->getGuia());

        return $this->render('panel/guias/incrustar.html.twig', ['guia' => $guia, 'bloque' => $bloque]);
    }

    /**
     * Mueve un bloque a otra sección, de esta pestaña, de otra pestaña o de otra guía que el usuario pueda editar.
     * El destino es "s{id}" (al principio o al final de una sección) o "p{id}" (una sección nueva al final de esa pestaña).
     * El bloque conserva su id, así que los códigos para incrustarlo siguen funcionando.
     */
    #[Route('/{id<\d+>}/bloques/{bloque<\d+>}/mover', name: 'panel_guia_bloque_mover', methods: ['GET', 'POST'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function moverBloque(Request $request, Guia $guia, #[MapEntity(id: 'bloque')] Bloque $bloque, GuiaRepository $guias, #[CurrentUser] Usuario $usuario): Response
    {
        $this->comprobarPertenencia($guia, $bloque->getGuia());
        $origen = $bloque->getSeccion();
        $destinos = $guias->editablesPor($usuario, $this->isGranted('ROLE_ADMINISTRADOR'));
        usort($destinos, fn (Guia $a, Guia $b) => [$b === $guia, mb_strtolower($a->getTitulo())] <=> [$a === $guia, mb_strtolower($b->getTitulo())]);

        if ($request->isMethod('POST')) {
            if (!$this->tokenValido($request, 'editar-guia-'.$guia->getId())) {
                throw $this->createAccessDeniedException();
            }
            $seccion = $this->seccionDestino($request->getPayload()->getString('destino'));
            if (null === $seccion) {
                $this->addFlash('error', 'guia.mover_sin_destino');

                return $this->redirectToRoute('panel_guia_bloque_mover', ['id' => $guia->getId(), 'bloque' => $bloque->getId()]);
            }
            $destino = $seccion->getGuia();
            $alInicio = 'inicio' === $request->getPayload()->getString('posicion');

            // Sin quitar el bloque de la colección de origen: con orphanRemoval Doctrine lo borraría.
            $restoOrigen = array_filter($origen->getBloques()->toArray(), fn (Bloque $b) => $b !== $bloque);
            $restoDestino = array_values(array_filter($seccion->getBloques()->toArray(), fn (Bloque $b) => $b !== $bloque));
            $renombres = $this->imagenes->prepararTraslado($bloque, $guia, $destino);
            $bloque->setSeccion($seccion);
            if (!$seccion->getBloques()->contains($bloque)) {
                $seccion->getBloques()->add($bloque);
            }
            foreach ($alInicio ? [$bloque, ...$restoDestino] : [...$restoDestino, $bloque] as $i => $b) {
                $b->setPosicion($i);
            }
            if ($seccion !== $origen) {
                $this->renumerar($restoOrigen);
            }
            $guia->tocar();
            $destino->tocar();
            $this->em->flush();
            $this->imagenes->renombrar($renombres);
            $this->addFlash('exito', $destino === $guia ? 'guia.bloque_movido' : 'guia.bloque_movido_guia');

            return $this->redirectToRoute('panel_guia_editar', ['id' => $destino->getId(), 'pestana' => $seccion->getPestana()?->getId(), '_fragment' => 'bloque-'.$bloque->getId()]);
        }

        return $this->render('panel/guias/mover.html.twig', [
            'guia' => $guia,
            'bloque' => $bloque,
            'destinos' => $destinos,
        ]);
    }

    #[Route('/{id<\d+>}/bloques/{bloque<\d+>}/eliminar', name: 'panel_guia_bloque_eliminar', methods: ['POST'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function eliminarBloque(Request $request, Guia $guia, #[MapEntity(id: 'bloque')] Bloque $bloque): Response
    {
        $this->comprobarPertenencia($guia, $bloque->getGuia());
        $seccion = $bloque->getSeccion();
        if ($this->tokenValido($request, 'editar-guia-'.$guia->getId())) {
            $seccion->removeBloque($bloque);
            $this->renumerar($seccion->getBloques());
            $this->guardar($guia);
            $this->addFlash('exito', 'guia.bloque_eliminado');
        }

        return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId(), 'pestana' => $seccion->getPestana()?->getId(), '_fragment' => 'seccion-'.$seccion->getId()]);
    }

    /**
     * Nuevo orden tras arrastrar y soltar. Cuerpo JSON: {"tipo": "pestanas"|"secciones"|"bloques", "padre": id, "ids": [...]}.
     * Para secciones el padre es la pestaña y para bloques la sección (un bloque puede pasar a otra sección de la guía).
     */
    #[Route('/{id<\d+>}/ordenar', name: 'panel_guia_ordenar', methods: ['POST'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function ordenar(Request $request, Guia $guia): Response
    {
        if (!$this->isCsrfTokenValid('editar-guia-'.$guia->getId(), (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['error' => 'token'], 403);
        }
        $datos = json_decode($request->getContent(), true);
        $ids = \is_array($datos) && \is_array($datos['ids'] ?? null) ? array_values(array_filter($datos['ids'], 'is_int')) : null;
        $padre = \is_array($datos) ? ($datos['padre'] ?? null) : null;
        if (null === $ids) {
            return new JsonResponse(['error' => 'datos'], 400);
        }

        $pestanas = $guia->getPestanas()->toArray();
        $secciones = array_merge([], ...array_map(fn (Pestana $p) => $p->getSecciones()->toArray(), $pestanas));
        $bloques = array_merge([], ...array_map(fn (Seccion $s) => $s->getBloques()->toArray(), $secciones));
        $porId = fn (array $lista) => array_combine(array_map(fn ($e) => $e->getId(), $lista), $lista);

        switch ($datos['tipo'] ?? null) {
            case 'pestanas':
                $this->aplicarOrden($porId($pestanas), $ids);
                break;
            case 'secciones':
                $pestana = $porId($pestanas)[$padre] ?? null;
                if (null === $pestana) {
                    return new JsonResponse(['error' => 'padre'], 400);
                }
                $this->aplicarOrden($porId($pestana->getSecciones()->toArray()), $ids);
                break;
            case 'bloques':
                $seccion = $porId($secciones)[$padre] ?? null;
                if (null === $seccion) {
                    return new JsonResponse(['error' => 'padre'], 400);
                }
                $todos = $porId($bloques);
                foreach ($ids as $posicion => $id) {
                    if (isset($todos[$id])) {
                        $todos[$id]->setSeccion($seccion)->setPosicion($posicion);
                    }
                }
                break;
            default:
                return new JsonResponse(['error' => 'tipo'], 400);
        }
        $this->guardar($guia);

        return new Response(null, 204);
    }

    /**
     * Listado filtrado (texto, tipo y materia) para elegir los recursos de un bloque.
     * Responde {total, pagina, paginas, elementos: [{id, nombre, detalle}]}; sin filtros lista todos.
     */
    #[Route('/buscar-recursos', name: 'panel_guias_buscar_recursos', methods: ['GET'])]
    public function buscarRecursos(Request $request, RecursoRepository $recursos, MateriaRepository $materias): JsonResponse
    {
        $consulta = $request->query;
        $texto = mb_substr(trim($consulta->getString('q')), 0, 100);
        $tipo = TipoRecurso::tryFrom($consulta->getString('tipo'));
        // Los filtros vacíos llegan como "" (getInt los rechazaría con 400).
        $materiaId = $consulta->getString('materia');
        $materia = ctype_digit($materiaId) ? $materias->find((int) $materiaId) : null;
        $numero = $consulta->getString('pagina');
        $pagina = $recursos->listadoAdministracion(ctype_digit($numero) ? max(1, (int) $numero) : 1, 50, $texto, $tipo, $materia);
        $idioma = $request->getLocale();

        return $this->json([
            'total' => $pagina->total,
            'pagina' => $pagina->numero,
            'paginas' => $pagina->getPaginas(),
            'elementos' => array_map(fn (Recurso $r) => [
                'id' => $r->getId(),
                'nombre' => $r->getTitulo(),
                'detalle' => implode(' · ', array_filter([
                    $this->traductor->trans($r->getTipo()->etiqueta(), [], null, $idioma),
                    $r->getProveedor() ?? $r->getEditorial()?->getNombre(),
                    $r->getAnio(),
                    $r->isVisible() ? null : $this->traductor->trans('recurso.oculto', [], null, $idioma),
                ])),
            ], $pagina->elementos),
        ]);
    }

    private function formularioBloque(Request $request, Guia $guia, Bloque $bloque, Seccion $seccion): Response
    {
        $tipo = $bloque->getTipo();
        $formulario = $this->createForm(BloqueType::class, $this->datosFormulario($bloque, $guia), ['tipo' => $tipo]);
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            $datos = $formulario->getData();
            $bloque->setTitulo($datos['titulo'] ?? null)->setContenido($this->contenido($tipo, $datos, $guia));
            if (null === $bloque->getId()) {
                $seccion->agregarBloque($bloque);
            }
            $this->guardar($guia);
            $this->addFlash('exito', 'guia.bloque_guardado');

            return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId(), 'pestana' => $seccion->getPestana()?->getId(), '_fragment' => 'bloque-'.$bloque->getId()]);
        }

        return $this->render('panel/guias/bloque.html.twig', [
            'formulario' => $formulario,
            'guia' => $guia,
            'bloque' => $bloque,
            'seccion' => $seccion,
            'tipos_recurso' => TipoRecurso::cases(),
            'materias' => TipoBloque::Recursos === $tipo ? $this->em->getRepository(Materia::class)->findBy(['activa' => true], ['nombre' => 'ASC']) : [],
            'buscador_libros' => TipoBloque::Libros === $tipo ? [
                'catalogo' => $this->catalogo->buscaPorIsbn(),
                'biblioges' => $this->biblioges->disponible(),
            ] : null,
            'buscador_revistas' => TipoBloque::Revistas === $tipo && $this->catalogo->buscaPorIsbn(),
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }

    /** @return array<string, mixed> */
    private function datosFormulario(Bloque $bloque, Guia $guia): array
    {
        $datos = ['titulo' => $bloque->getTitulo()];

        return $datos + match ($bloque->getTipo()) {
            TipoBloque::Texto => ['html' => $bloque->dato('html')],
            TipoBloque::Recursos => [
                'recursos' => json_encode(array_map(
                    fn (Recurso $r) => ['id' => $r->getId(), 'nombre' => $r->getTitulo()],
                    $this->bloques->recursos($bloque, false),
                ), \JSON_UNESCAPED_UNICODE),
                'descripcion' => (bool) $bloque->dato('descripcion', true),
            ],
            TipoBloque::Perfil => ['usuario' => $this->bloques->perfil($bloque)],
            TipoBloque::Video => ['url' => $bloque->dato('url'), 'descripcion' => $bloque->dato('descripcion')],
            TipoBloque::Busqueda => [
                'texto' => $bloque->dato('texto'),
                'tipo' => $bloque->dato('tipo', 'todos'),
                'materias' => $this->bloques->materiasBusqueda($bloque, $guia),
                'otras' => (bool) $bloque->dato('otras', true),
            ],
            TipoBloque::Libros, TipoBloque::Revistas => [
                'items' => $this->librosConMaterias($bloque->dato('items', [])),
                'presentacion' => $bloque->dato('presentacion', 'cuadricula'),
                'columnas' => (int) $bloque->dato('columnas', 0),
                'orden' => 'titulo' === $bloque->dato('orden') ? 'titulo' : 'manual',
            ],
            TipoBloque::Enlaces, TipoBloque::Diapositivas => ['items' => $bloque->dato('items', [])],
        };
    }

    /**
     * Con el orden «por título» los libros se guardan ordenados alfabéticamente (según el orden del español),
     * así la guía y el editor los muestran igual; con el orden manual quedan como los dejó el editor.
     *
     * @param array<string, mixed> $contenido
     *
     * @return array<string, mixed>
     */
    private static function ordenarLibros(array $contenido): array
    {
        if ('titulo' === $contenido['orden']) {
            $comparador = class_exists(\Collator::class) ? new \Collator('es') : null;
            usort($contenido['items'], fn (array $a, array $b): int => null !== $comparador
                ? $comparador->compare((string) $a['titulo'], (string) $b['titulo'])
                : strnatcasecmp((string) $a['titulo'], (string) $b['titulo']));
        }

        return $contenido;
    }

    /**
     * Orden, presentación y columnas de los bloques Libros y Revistas.
     *
     * @param array<string, mixed> $datos
     *
     * @return array{orden: string, presentacion: string, columnas: int}
     */
    private static function presentacion(array $datos): array
    {
        return [
            'orden' => 'titulo' === ($datos['orden'] ?? null) ? 'titulo' : 'manual',
            'presentacion' => \in_array($datos['presentacion'] ?? null, ['carrusel', 'lista'], true) ? $datos['presentacion'] : 'cuadricula',
            'columnas' => \in_array((int) ($datos['columnas'] ?? 0), [2, 3, 4, 5, 6], true) ? (int) $datos['columnas'] : 0,
        ];
    }

    /**
     * @param array<string, mixed> $datos
     *
     * @return array<string, mixed>
     */
    private function contenido(TipoBloque $tipo, array $datos, Guia $guia): array
    {
        $texto = fn (mixed $valor): ?string => '' === trim((string) $valor) ? null : trim((string) $valor);
        $items = array_values(array_filter($datos['items'] ?? [], 'is_array'));

        return match ($tipo) {
            TipoBloque::Texto => ['html' => $this->saneador->sanitize((string) ($datos['html'] ?? ''))],
            TipoBloque::Recursos => [
                'ids' => $this->idsRecursos((string) ($datos['recursos'] ?? '')),
                'descripcion' => (bool) ($datos['descripcion'] ?? false),
            ],
            TipoBloque::Perfil => ['usuario' => $datos['usuario'] instanceof Usuario ? $datos['usuario']->getId() : null],
            TipoBloque::Video => ['url' => $datos['url'] ?? null, 'descripcion' => trim((string) ($datos['descripcion'] ?? '')) ?: null],
            TipoBloque::Busqueda => [
                'texto' => trim((string) ($datos['texto'] ?? '')) ?: null,
                'tipo' => isset(BloquesGuia::TIPOS_MATERIAL[$datos['tipo'] ?? '']) ? $datos['tipo'] : 'todos',
                'materias' => array_values(array_map(fn (Materia $m) => $m->getId(), array_filter([...($datos['materias'] ?? [])], fn ($m) => $m instanceof Materia))),
                'otras' => (bool) ($datos['otras'] ?? false),
            ],
            TipoBloque::Enlaces => ['items' => array_map(fn (array $i) => [
                'titulo' => $texto($i['titulo'] ?? null), 'url' => $texto($i['url'] ?? null), 'descripcion' => $texto($i['descripcion'] ?? null),
            ], $items)],
            TipoBloque::Libros => self::ordenarLibros([
                ...self::presentacion($datos),
                // Cada libro se incorpora a la lista de recursos y guarda el id del recurso con que quedó vinculado.
                'items' => $this->librosEnRecursos->incorporar(array_map(fn (array $i) => [
                    'recurso' => ctype_digit((string) ($i['recurso'] ?? '')) ? (int) $i['recurso'] : null,
                    'titulo' => $texto($i['titulo'] ?? null),
                    'autor' => $texto($i['autor'] ?? null),
                    'isbn' => $texto(preg_replace('/[^0-9X]/', '', strtoupper((string) ($i['isbn'] ?? '')))),
                    'doi' => MetadatosDoi::normalizar($texto($i['doi'] ?? null)),
                    'editorial' => $texto($i['editorial'] ?? null),
                    'anio' => $texto($i['anio'] ?? null),
                    'enlace' => $texto($i['enlace'] ?? null),
                    // Del catálogo (DatosLibro): registro en Primo VE y MMS id de Alma.
                    'registro' => $texto($i['registro'] ?? null),
                    'mms' => $texto($i['mms'] ?? null),
                ], $items), array_map(fn (array $i) => $i['materias'] ?? null, $items), $guia),
            ]),
            TipoBloque::Revistas => self::ordenarLibros([
                ...self::presentacion($datos),
                // Como los libros, cada revista se incorpora a la lista de recursos (tipo Revistas).
                'items' => $this->revistasEnRecursos->incorporar(array_map(fn (array $i) => [
                    'recurso' => ctype_digit((string) ($i['recurso'] ?? '')) ? (int) $i['recurso'] : null,
                    'titulo' => $texto($i['titulo'] ?? null),
                    'issn' => Catalogo::issn($texto($i['issn'] ?? null)),
                    'eissn' => Catalogo::issn($texto($i['eissn'] ?? null)),
                    'editorial' => $texto($i['editorial'] ?? null),
                    'enlace' => $texto($i['enlace'] ?? null),
                    // Del catálogo (DatosRevista): registro en Primo VE y MMS id de Alma.
                    'registro' => $texto($i['registro'] ?? null),
                    'mms' => $texto($i['mms'] ?? null),
                ], $items), array_map(fn (array $i) => $i['materias'] ?? null, $items), $guia),
            ]),
            TipoBloque::Diapositivas => ['items' => array_values(array_filter(array_map(function (array $i) use ($texto, $guia) {
                $imagen = (string) ($i['imagen'] ?? '');
                if (($i['archivo'] ?? null) instanceof UploadedFile) {
                    $imagen = $this->imagenes->guardarDiapositiva($guia, $i['archivo']);
                } elseif (!$this->imagenes->esDiapositivaDe($guia, $imagen)) {
                    return null;
                }

                return ['imagen' => $imagen, 'titulo' => $texto($i['titulo'] ?? null), 'enlace' => $texto($i['enlace'] ?? null)];
            }, $items)))],
        };
    }

    /**
     * Libros guardados en el bloque, con las materias del recurso vinculado para el formulario.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function librosConMaterias(array $items): array
    {
        $ids = array_values(array_filter(array_map(fn (array $i) => $i['recurso'] ?? null, $items), 'is_int'));
        $recursos = [];
        foreach ([] === $ids ? [] : $this->em->getRepository(Recurso::class)->findBy(['id' => $ids]) as $recurso) {
            $recursos[$recurso->getId()] = $recurso;
        }

        return array_map(function (array $i) use ($recursos): array {
            $recurso = \is_int($i['recurso'] ?? null) ? ($recursos[$i['recurso']] ?? null) : null;
            $i['recurso'] = $recurso?->getId();
            $i['materias'] = null === $recurso ? [] : array_values(array_filter($recurso->getMaterias()->toArray(), fn (Materia $m) => $m->isActiva()));

            return $i;
        }, $items);
    }

    /** @return list<int> ids de recursos existentes, en el orden elegido y sin repetir */
    private function idsRecursos(string $json): array
    {
        $elegidos = json_decode($json, true);
        $ids = [];
        foreach (\is_array($elegidos) ? \array_slice($elegidos, 0, 200) : [] as $elegido) {
            if (\is_array($elegido) && \is_int($elegido['id'] ?? null)) {
                $ids[$elegido['id']] = true;
            }
        }
        if ([] === $ids) {
            return [];
        }
        $existentes = array_flip(array_map(fn (Recurso $r) => $r->getId(), $this->em->getRepository(Recurso::class)->findBy(['id' => array_keys($ids)])));

        return array_values(array_filter(array_keys($ids), fn (int $id) => isset($existentes[$id])));
    }

    private function pestanaElegida(Guia $guia, int $id): ?Pestana
    {
        foreach ($guia->getPestanas() as $pestana) {
            if ($pestana->getId() === $id) {
                return $pestana;
            }
        }

        return $guia->getPestana() ?? $guia->getPestanasPrincipales()[0] ?? null;
    }

    /**
     * Sección de destino de «Mover bloque»: "s{id}" es una sección existente y "p{id}" crea una sección nueva al final
     * de esa pestaña. Null si no existe, si es una pestaña de enlace externo o si el usuario no puede editar esa guía.
     */
    private function seccionDestino(string $valor): ?Seccion
    {
        if (1 !== preg_match('/^([sp])(\d+)$/', $valor, $m)) {
            return null;
        }
        $pestana = 's' === $m[1] ? $this->em->find(Seccion::class, (int) $m[2])?->getPestana() : $this->em->find(Pestana::class, (int) $m[2]);
        $guia = $pestana?->getGuia();
        if (null === $guia || $pestana->isEnlaceExterno() || !$this->isGranted(GuiaVoter::EDITAR, $guia)) {
            return null;
        }
        if ('s' === $m[1]) {
            return $this->em->find(Seccion::class, (int) $m[2]);
        }
        $seccion = new Seccion();
        $pestana->agregarSeccion($seccion);

        return $seccion;
    }

    private function enlaceValido(string $enlace): bool
    {
        return mb_strlen($enlace) <= 500 && 1 === preg_match('#^https?://[^\s.]+\.[^\s]+$#i', $enlace)
            && false !== filter_var($enlace, \FILTER_VALIDATE_URL);
    }

    /**
     * @param array<int, Pestana|Seccion> $porId
     * @param list<int>                   $ids
     */
    private function aplicarOrden(array $porId, array $ids): void
    {
        $posicion = 0;
        foreach ($ids as $id) {
            if (isset($porId[$id])) {
                $porId[$id]->setPosicion($posicion++);
                unset($porId[$id]);
            }
        }
        foreach ($porId as $resto) { // los que no vinieron quedan al final
            $resto->setPosicion($posicion++);
        }
    }

    /** @param iterable<Pestana|Seccion|Bloque> $elementos */
    private function renumerar(iterable $elementos): void
    {
        $lista = [...$elementos];
        usort($lista, fn ($a, $b) => $a->getPosicion() <=> $b->getPosicion());
        foreach ($lista as $i => $elemento) {
            $elemento->setPosicion($i);
        }
    }

    private function comprobarPertenencia(Guia $guia, ?Guia $duena): void
    {
        if ($duena !== $guia) {
            throw $this->createNotFoundException();
        }
    }

    private function guardar(Guia $guia): void
    {
        $guia->tocar();
        $this->em->flush();
        $this->imagenes->podarDiapositivas($guia); // imágenes de diapositivas quitadas o de bloques eliminados
    }

    private function tokenValido(Request $request, string $id): bool
    {
        return $this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'));
    }

    private function trans(string $clave, string $idioma): string
    {
        return $this->traductor->trans($clave, [], null, $idioma);
    }
}
