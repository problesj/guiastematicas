<?php

declare(strict_types=1);

namespace App\Controller;

use App\Captcha\Captcha;
use App\Correo\AvisosConsulta;
use App\Entity\Consulta;
use App\Entity\Usuario;
use App\Form\ConsultaType;
use App\Repository\MateriaRepository;
use App\Repository\UsuarioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Directorio público de bibliotecarios, filtrable por materia, y ficha de cada uno con el formulario de contacto. */
#[Route('/{_locale}/personal', requirements: ['_locale' => 'es|en'])]
final class PersonalController extends AbstractController
{
    #[Route('', name: 'personal', methods: ['GET'])]
    public function directorio(Request $request, UsuarioRepository $usuarios, MateriaRepository $materias): Response
    {
        $slug = $request->query->getString('materia');
        $materia = '' !== $slug ? $materias->findOneBy(['slug' => $slug, 'activa' => true]) : null;

        // Agrupa por departamento conservando el orden de la consulta; quien no tiene departamento va al final.
        $grupos = [];
        $sinDepartamento = [];
        foreach ($usuarios->directorio($materia) as $persona) {
            $departamento = $persona->getDepartamento();
            if (null === $departamento) {
                $sinDepartamento[] = $persona;
                continue;
            }
            $grupos[$departamento->getId()] ??= ['departamento' => $departamento, 'personas' => []];
            $grupos[$departamento->getId()]['personas'][] = $persona;
        }
        if ([] !== $sinDepartamento) {
            $grupos[0] = ['departamento' => null, 'personas' => $sinDepartamento];
        }

        return $this->render('personal/directorio.html.twig', [
            'grupos' => $grupos,
            'materias' => $materias->activas(),
            'materia' => $materia,
        ]);
    }

    #[Route('/{slug}', name: 'personal_perfil', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET', 'POST'])]
    public function perfil(
        string $slug,
        Request $request,
        UsuarioRepository $usuarios,
        Captcha $captcha,
        EntityManagerInterface $em,
        AvisosConsulta $avisos,
        TranslatorInterface $traductor,
        #[Target('contacto')] RateLimiterFactoryInterface $limitador,
    ): Response {
        $persona = $usuarios->findOneBy(['slug' => $slug]);
        if (!$persona instanceof Usuario || !$persona->isActivo() || !$persona->isVisibleEnDirectorio() || !$persona->getRol()->esPersonal()) {
            throw $this->createNotFoundException();
        }

        // Formulario «Escribir a…»: la consulta queda registrada para el bibliotecario y se avisa por correo a ambos.
        $consulta = (new Consulta($persona))->setIdioma($request->getLocale());
        $formulario = $this->createForm(ConsultaType::class, $consulta, ['action' => $this->generateUrl('personal_perfil', ['slug' => $slug]).'#escribir']);
        $formulario->handleRequest($request);
        $estado = 200;
        if ($formulario->isSubmitted()) {
            $estado = 422;
            if (!$limitador->create($request->getClientIp() ?? 'desconocida')->consume()->isAccepted()) {
                $formulario->addError(new FormError($traductor->trans('consulta.demasiados')));
                $estado = 429;
            } elseif (!$captcha->verificar($formulario->get('captcha')->getData())) {
                $formulario->addError(new FormError($traductor->trans('sugerencia.captcha_fallido')));
            } elseif ($formulario->isValid()) {
                $mensaje = $consulta->escribePersona((string) $formulario->get('mensaje')->getData());
                $em->persist($consulta);
                $em->flush();
                $avisos->nueva($mensaje);
                $em->flush();
                $this->addFlash('exito', $traductor->trans('consulta.enviada', ['%correo%' => $consulta->getCorreo()]));

                return $this->redirect($this->generateUrl('personal_perfil', ['slug' => $slug]).'#escribir');
            }
        }

        return $this->render('personal/perfil.html.twig', [
            'persona' => $persona,
            'formulario' => $formulario,
            'desafio' => $captcha->desafio(),
        ], new Response(status: $estado));
    }
}
