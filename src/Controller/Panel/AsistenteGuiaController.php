<?php

declare(strict_types=1);

namespace App\Controller\Panel;

use App\Configuracion\Ajustes;
use App\Entity\Guia;
use App\Entity\Usuario;
use App\Enum\TipoBloque;
use App\Form\AsistenteGuiaType;
use App\Servicio\AlmacenImagenesGuia;
use App\Servicio\AsistenteGuias;
use App\Servicio\Biblioges;
use App\Servicio\ContenidoCompartido;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «Nueva guía asistida»: pide materias y carreras y arma la guía completa (ver AsistenteGuias), en borrador.
 * Los administradores eligen aquí el contenido transversal que se vincula en cada guía.
 */
#[Route('/{_locale}/panel/guias/asistente', requirements: ['_locale' => 'es|en'])]
#[IsGranted('ROLE_EDITOR')]
final class AsistenteGuiaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AsistenteGuias $asistente,
        private readonly ContenidoCompartido $compartido,
    ) {
    }

    #[Route('', name: 'panel_guia_asistente', methods: ['GET', 'POST'])]
    public function crear(Request $request, Biblioges $biblioges, AlmacenImagenesGuia $imagenes, #[CurrentUser] Usuario $usuario): Response
    {
        $carreras = [];
        foreach ($biblioges->carreras() as $carrera) {
            $carreras[$carrera['codigo']] = $carrera['nombre'].' ('.$carrera['codigo'].($carrera['sede'] ? ', '.$carrera['sede'] : '').')';
        }
        $guia = (new Guia())->setResponsable($usuario)->setIdioma($request->getLocale());
        $formulario = $this->createForm(AsistenteGuiaType::class, $guia, ['carreras' => $carreras, 'ia' => $this->asistente->iaDisponible()]);
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            $this->em->persist($guia);
            $this->em->flush();
            $elegidas = $formulario->has('carreras') ? array_values(array_map('strval', (array) $formulario->get('carreras')->getData())) : []; // los códigos numéricos llegan como int
            $usarIa = $this->asistente->iaDisponible() && true === $formulario->get('usar_ia')->getData();
            $avisos = $this->asistente->armar($guia, $elegidas, $usarIa);
            $guia->tocar();
            $this->em->flush();
            $imagenes->podarDiapositivas($guia);

            $this->addFlash('exito', $usarIa && !\in_array('asistente.aviso_ia_fallo', $avisos, true) ? 'asistente.creada_ia' : 'asistente.creada');
            foreach ($avisos as $aviso) {
                $this->addFlash('aviso', $aviso);
            }

            return $this->redirectToRoute('panel_guia_editar', ['id' => $guia->getId()]);
        }

        $administrador = $this->isGranted('ROLE_ADMINISTRADOR');

        return $this->render('panel/guias/asistente.html.twig', [
            'formulario' => $formulario,
            'ia' => $this->asistente->iaDisponible(),
            'biblioges' => [] !== $carreras,
            'multidisciplinarias' => $this->asistente->bloqueMultidisciplinarias(),
            'citacion' => $this->asistente->pestanaCitacion(),
            'bloques_transversales' => $administrador ? $this->compartido->bloquesReutilizables(null, '', TipoBloque::Recursos) : [],
            'pestanas_transversales' => $administrador ? $this->compartido->pestanasReutilizables() : [],
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }

    /** Contenido transversal que vincula el asistente: un bloque de bases multidisciplinarias y la pestaña de citación. */
    #[Route('/transversal', name: 'panel_guia_asistente_transversal', methods: ['POST'])]
    #[IsGranted('ROLE_ADMINISTRADOR')]
    public function transversal(Request $request, Ajustes $ajustes): Response
    {
        if ($this->isCsrfTokenValid('asistente-transversal', $request->getPayload()->getString('_token'))) {
            foreach (['IA_BLOQUE_MULTIDISCIPLINARIAS' => 'bloque', 'IA_PESTANA_CITACION' => 'pestana'] as $clave => $campo) {
                $valor = $request->getPayload()->getString($campo);
                $ajustes->guardar($clave, ctype_digit($valor) ? $valor : null);
            }
            $this->addFlash('exito', 'asistente.transversal_guardado');
        }

        return $this->redirectToRoute('panel_guia_asistente');
    }
}
