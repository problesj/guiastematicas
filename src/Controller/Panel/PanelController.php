<?php

declare(strict_types=1);

namespace App\Controller\Panel;

use App\Entity\Usuario;
use App\Form\CambioContrasenaType;
use App\Form\UsuarioType;
use App\Servicio\AlmacenFotos;
use App\Servicio\GeneradorSlug;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Área privada del personal (cualquier rol). */
#[Route('/{_locale}/panel', requirements: ['_locale' => 'es|en'])]
final class PanelController extends AbstractController
{
    #[Route('', name: 'panel', methods: ['GET'])]
    public function inicio(): Response
    {
        return $this->render('panel/inicio.html.twig');
    }

    #[Route('/perfil', name: 'panel_perfil', methods: ['GET', 'POST'])]
    public function perfil(
        Request $request,
        #[CurrentUser] Usuario $usuario,
        EntityManagerInterface $em,
        GeneradorSlug $slugs,
        AlmacenFotos $fotos,
    ): Response {
        $formulario = $this->createForm(UsuarioType::class, $usuario, ['modo' => 'perfil', 'tiene_foto' => null !== $usuario->getFoto()]);
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            self::aplicarFoto($formulario, $usuario, $fotos);
            $usuario->setSlug($slugs->generar(Usuario::class, $usuario->getNombreCompleto(), $usuario->getId()));
            $em->flush();
            $this->addFlash('exito', 'perfil.guardado');

            return $this->redirectToRoute('panel_perfil');
        }

        if ($formulario->isSubmitted()) {
            // Evita que un nombre inválido quede en la sesión del usuario conectado.
            $em->refresh($usuario);
        }

        return $this->render('panel/perfil.html.twig', ['formulario' => $formulario]);
    }

    #[Route('/contrasena', name: 'panel_contrasena', methods: ['GET', 'POST'])]
    public function contrasena(
        Request $request,
        #[CurrentUser] Usuario $usuario,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $formulario = null;
        if ($usuario->tieneContrasenaPropia()) {
            $formulario = $this->createForm(CambioContrasenaType::class);
            $formulario->handleRequest($request);

            if ($formulario->isSubmitted() && $formulario->isValid()) {
                $usuario->setPassword($hasher->hashPassword($usuario, $formulario->get('nueva')->getData()));
                $em->flush();
                $this->addFlash('exito', 'contrasena.cambiada');

                return $this->redirectToRoute('panel');
            }
        }

        return $this->render('panel/contrasena.html.twig', ['formulario' => $formulario]);
    }

    /** Aplica los campos de foto (subir nueva o eliminar) de un UsuarioType ya validado. */
    public static function aplicarFoto(\Symfony\Component\Form\FormInterface $formulario, Usuario $usuario, AlmacenFotos $fotos): void
    {
        $archivo = $formulario->get('archivoFoto')->getData();
        if (null !== $archivo) {
            $usuario->setFoto($fotos->guardar($archivo, $usuario->getFoto()));
        } elseif ($formulario->has('eliminarFoto') && true === $formulario->get('eliminarFoto')->getData()) {
            $fotos->eliminar($usuario->getFoto());
            $usuario->setFoto(null);
        }
    }
}
