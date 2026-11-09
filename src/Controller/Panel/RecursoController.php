<?php

declare(strict_types=1);

namespace App\Controller\Panel;

use App\Entity\Recurso;
use App\Enum\TipoRecurso;
use App\Form\RecursoType;
use App\Repository\MateriaRepository;
use App\Repository\RecursoRepository;
use App\Servicio\Importacion\ArchivoNoValidoException;
use App\Servicio\Importacion\ImportadorRecursos;
use App\Servicio\Importacion\PlantillaImportacion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Gestión de la lista A-Z: editores y administradores. */
#[Route('/{_locale}/panel/recursos', requirements: ['_locale' => 'es|en'])]
#[IsGranted('ROLE_EDITOR')]
final class RecursoController extends AbstractController
{
    private const array EXTENSIONES = ['xlsx', 'xls', 'ods', 'csv', 'bib', 'bibtex', 'ris'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/var/importaciones')]
        private readonly string $directorioImportaciones,
    ) {
    }

    #[Route('', name: 'panel_recursos', methods: ['GET'])]
    public function listado(Request $request, RecursoRepository $recursos): Response
    {
        $texto = mb_substr(trim($request->query->getString('q')), 0, 100);

        return $this->render('panel/recursos/listado.html.twig', [
            'recursos' => $recursos->listadoAdministracion($texto),
            'q' => $texto,
        ]);
    }

    #[Route('/nuevo', name: 'panel_recurso_nuevo', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        return $this->formulario($request, new Recurso(), 'recurso.nuevo');
    }

    #[Route('/{id<\d+>}/editar', name: 'panel_recurso_editar', methods: ['GET', 'POST'])]
    public function editar(Request $request, Recurso $recurso): Response
    {
        return $this->formulario($request, $recurso, 'recurso.editar');
    }

    #[Route('/{id<\d+>}/eliminar', name: 'panel_recurso_eliminar', methods: ['POST'])]
    public function eliminar(Request $request, Recurso $recurso): Response
    {
        if ($this->isCsrfTokenValid('eliminar-recurso-'.$recurso->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($recurso);
            $this->em->flush();
            $this->addFlash('exito', 'recurso.eliminado');
        }

        return $this->redirectToRoute('panel_recursos');
    }

    /** Paso 1: subir el archivo y ver qué se va a crear, actualizar u omitir. */
    #[Route('/importar', name: 'panel_recursos_importar', methods: ['GET', 'POST'])]
    public function importar(Request $request, ImportadorRecursos $importador, MateriaRepository $materias): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->render('panel/recursos/importar.html.twig', ['columnas' => ImportadorRecursos::COLUMNAS]);
        }

        if (!$this->isCsrfTokenValid('importar-recursos', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->limpiarImportacionesAntiguas();
        $archivo = $request->files->get('archivo');
        $error = $this->validarArchivo($archivo);
        if (null === $error) {
            $nombre = bin2hex(random_bytes(16)).'.'.strtolower($archivo->getClientOriginalExtension());
            $archivo->move($this->directorioImportaciones, $nombre);
            try {
                $filas = $importador->leer($this->directorioImportaciones.'/'.$nombre);

                return $this->render('panel/recursos/vista_previa.html.twig', [
                    'filas' => $filas,
                    'materias' => $materias->activas(),
                    'materias_por_fila' => $importador->clasificarMaterias($filas),
                    'archivo' => $nombre,
                    'nombre_original' => $archivo->getClientOriginalName(),
                    'cuentas' => array_count_values(array_map(fn ($f) => $f->accion(), $filas)),
                ]);
            } catch (ArchivoNoValidoException $e) {
                (new Filesystem())->remove($this->directorioImportaciones.'/'.$nombre);
                $error = $e->getMessage();
            }
        }

        return $this->render('panel/recursos/importar.html.twig', [
            'columnas' => ImportadorRecursos::COLUMNAS,
            'error' => $error,
        ], new Response(status: 422));
    }

    /** Paso 2: aplicar la importación del archivo revisado en la vista previa. */
    #[Route('/importar/confirmar', name: 'panel_recursos_importar_confirmar', methods: ['POST'])]
    public function confirmarImportacion(Request $request, ImportadorRecursos $importador, TranslatorInterface $traductor): Response
    {
        $nombre = $request->getPayload()->getString('archivo');
        $ruta = $this->directorioImportaciones.'/'.$nombre;
        if (!$this->isCsrfTokenValid('confirmar-importacion', $request->getPayload()->getString('_token'))
            || 1 !== preg_match('/^[a-f0-9]{32}\.(xlsx|xls|ods|csv|bib|bibtex|ris)$/', $nombre)
            || !is_file($ruta)) {
            $this->addFlash('error', 'importacion.expirada');

            return $this->redirectToRoute('panel_recursos_importar');
        }

        try {
            $filas = $importador->leer($ruta);
            // Lo elegido fila a fila en la vista previa (JSON armado en el navegador); sin él se importan las filas válidas tal cual.
            $seleccion = json_decode($request->getPayload()->getString('seleccion'), true);
            if (\is_array($seleccion)) {
                $importador->aplicarSeleccion($filas, $seleccion);
            }
            $resultado = $importador->aplicar($filas);
        } finally {
            (new Filesystem())->remove($ruta);
        }

        $this->addFlash('exito', $traductor->trans('importacion.resultado', [
            '%creados%' => $resultado['creados'],
            '%actualizados%' => $resultado['actualizados'],
            '%omitidos%' => $resultado['omitidos'],
            '%materias%' => $resultado['materias_nuevas'],
        ]));

        return $this->redirectToRoute('panel_recursos');
    }

    #[Route('/plantilla.xlsx', name: 'panel_recursos_plantilla', methods: ['GET'])]
    public function plantilla(PlantillaImportacion $plantilla): StreamedResponse
    {
        return new StreamedResponse(fn () => $plantilla->escribir('php://output'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="plantilla-recursos.xlsx"',
        ]);
    }

    /** Borra los archivos de vistas previas que nunca se confirmaron (más de un día). */
    private function limpiarImportacionesAntiguas(): void
    {
        foreach (glob($this->directorioImportaciones.'/*') ?: [] as $archivo) {
            if (filemtime($archivo) < time() - 86400) {
                @unlink($archivo);
            }
        }
    }

    private function validarArchivo(mixed $archivo): ?string
    {
        if (!$archivo instanceof UploadedFile || !$archivo->isValid()) {
            return 'importacion.sin_archivo';
        }
        if (!\in_array(strtolower($archivo->getClientOriginalExtension()), self::EXTENSIONES, true)) {
            return 'importacion.extension';
        }
        if ($archivo->getSize() > 10 * 1024 * 1024) {
            return 'importacion.muy_grande';
        }

        return null;
    }

    private function formulario(Request $request, Recurso $recurso, string $titulo): Response
    {
        $formulario = $this->createForm(RecursoType::class, $recurso);
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            $this->em->persist($recurso);
            $this->em->flush();
            $this->addFlash('exito', 'recurso.guardado');

            return $this->redirectToRoute('panel_recursos');
        }

        return $this->render('panel/recursos/formulario.html.twig', [
            'formulario' => $formulario,
            'titulo' => $titulo,
            'campos_bibliograficos' => TipoRecurso::CAMPOS_BIBLIOGRAFICOS,
            'tipos_por_campo' => array_combine(TipoRecurso::CAMPOS_BIBLIOGRAFICOS, array_map(TipoRecurso::tiposConCampo(...), TipoRecurso::CAMPOS_BIBLIOGRAFICOS)),
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }
}
