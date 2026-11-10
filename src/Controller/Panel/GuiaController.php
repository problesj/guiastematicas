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
use App\Servicio\BloquesGuia;
use App\Servicio\DatosLibro;
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

    /** Código para mostrar el bloque en otra web (iframe o /incrustar.js). */
    #[Route('/{id<\d+>}/bloques/{bloque<\d+>}/incrustar', name: 'panel_guia_bloque_incrustar', methods: ['GET'])]
    #[IsGranted(GuiaVoter::EDITAR, 'guia')]
    public function incrustarBloque(Guia $guia, #[MapEntity(id: 'bloque')] Bloque $bloque): Response
    {
        $this->comprobarPertenencia($guia, $bloque->getGuia());

        return $this->render('panel/guias/incrustar.html.twig', ['guia' => $guia, 'bloque' => $bloque]);
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
        $formulario = $this->createForm(BloqueType::class, $this->datosFormulario($bloque), ['tipo' => $tipo]);
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
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }

    /** @return array<string, mixed> */
    private function datosFormulario(Bloque $bloque): array
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
            TipoBloque::Busqueda => ['texto' => $bloque->dato('texto')],
            TipoBloque::Enlaces, TipoBloque::Libros, TipoBloque::Diapositivas => ['items' => $bloque->dato('items', [])],
        };
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
            TipoBloque::Busqueda => ['texto' => trim((string) ($datos['texto'] ?? '')) ?: null],
            TipoBloque::Enlaces => ['items' => array_map(fn (array $i) => [
                'titulo' => $texto($i['titulo'] ?? null), 'url' => $texto($i['url'] ?? null), 'descripcion' => $texto($i['descripcion'] ?? null),
            ], $items)],
            TipoBloque::Libros => ['items' => array_map(fn (array $i) => [
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
            ], $items)],
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
