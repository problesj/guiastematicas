<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Usuario;
use App\Repository\MateriaRepository;
use App\Repository\UsuarioRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Directorio público de bibliotecarios, filtrable por materia. */
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

    #[Route('/{slug}', name: 'personal_perfil', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function perfil(string $slug, UsuarioRepository $usuarios): Response
    {
        $persona = $usuarios->findOneBy(['slug' => $slug]);
        if (!$persona instanceof Usuario || !$persona->isActivo() || !$persona->isVisibleEnDirectorio() || !$persona->getRol()->esPersonal()) {
            throw $this->createNotFoundException();
        }

        return $this->render('personal/perfil.html.twig', ['persona' => $persona]);
    }
}
