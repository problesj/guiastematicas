<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Panel\PanelController;
use App\Entity\Usuario;
use App\Enum\Rol;
use App\Form\UsuarioType;
use App\Repository\UsuarioRepository;
use App\Servicio\AlmacenFotos;
use App\Servicio\GeneradorSlug;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/{_locale}/admin/usuarios', requirements: ['_locale' => 'es|en'])]
final class UsuarioController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UsuarioRepository $usuarios,
        private readonly GeneradorSlug $slugs,
        private readonly AlmacenFotos $fotos,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TranslatorInterface $traductor,
    ) {
    }

    #[Route('', name: 'admin_usuarios', methods: ['GET'])]
    public function listado(): Response
    {
        return $this->render('admin/usuarios/listado.html.twig', ['usuarios' => $this->usuarios->listadoAdministracion()]);
    }

    #[Route('/nuevo', name: 'admin_usuario_nuevo', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        $usuario = (new Usuario())->setRol(Rol::Editor);

        return $this->formulario($request, $usuario, 'usuario.nuevo');
    }

    #[Route('/{id<\d+>}/editar', name: 'admin_usuario_editar', methods: ['GET', 'POST'])]
    public function editar(Request $request, Usuario $usuario): Response
    {
        return $this->formulario($request, $usuario, 'usuario.editar');
    }

    private function formulario(Request $request, Usuario $usuario, string $titulo): Response
    {
        $rolAnterior = $usuario->getRol();
        $activoAnterior = $usuario->isActivo();
        $formulario = $this->createForm(UsuarioType::class, $usuario, ['modo' => 'admin', 'tiene_foto' => null !== $usuario->getFoto()]);
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid() && $this->validarReglas($formulario, $usuario, $rolAnterior, $activoAnterior)) {
            $contrasena = $formulario->get('nuevaContrasena')->getData();
            if (null !== $contrasena && '' !== $contrasena) {
                $usuario->setPassword($this->hasher->hashPassword($usuario, $contrasena));
            }
            PanelController::aplicarFoto($formulario, $usuario, $this->fotos);
            $usuario->setSlug($this->slugs->generar(Usuario::class, $usuario->getNombreCompleto(), $usuario->getId()));

            $this->em->persist($usuario);
            $this->em->flush();
            $this->addFlash('exito', 'usuario.guardado');

            return $this->redirectToRoute('admin_usuarios');
        }

        return $this->render('admin/usuarios/formulario.html.twig', [
            'formulario' => $formulario,
            'usuario' => $usuario,
            'titulo' => $titulo,
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }

    /** Siempre debe quedar al menos un administrador activo. */
    private function validarReglas(FormInterface $formulario, Usuario $usuario, Rol $rolAnterior, bool $activoAnterior): bool
    {
        $eraAdministradorActivo = null !== $usuario->getId() && Rol::Administrador === $rolAnterior && $activoAnterior;
        $dejaDeSerlo = Rol::Administrador !== $usuario->getRol() || !$usuario->isActivo();

        if ($eraAdministradorActivo && $dejaDeSerlo && $this->usuarios->contarAdministradoresActivos() <= 1) {
            $formulario->get('rol')->addError(new FormError($this->traductor->trans('usuario.ultimo_administrador')));

            return false;
        }

        return true;
    }
}
