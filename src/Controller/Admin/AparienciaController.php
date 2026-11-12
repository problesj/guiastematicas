<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Configuracion\Ajustes;
use App\Configuracion\Apariencia;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Nombre del sitio, logo, imagen de la portada y colores. Los colores deben tener contraste AA con el texto que llevan encima. */
#[Route('/{_locale}/admin/apariencia', requirements: ['_locale' => 'es|en'])]
final class AparienciaController extends AbstractController
{
    public function __construct(private readonly Ajustes $ajustes, private readonly Apariencia $apariencia, private readonly TranslatorInterface $traductor)
    {
    }

    #[Route('', name: 'admin_apariencia', methods: ['GET', 'POST'])]
    public function editar(Request $request): Response
    {
        $constructor = $this->createFormBuilder(null, ['translation_domain' => 'messages']);
        foreach (['SITIO_TITULO', 'SITIO_TITULO_EN', 'SITIO_INSTITUCION'] as $clave) {
            $constructor->add($clave, TextType::class, [
                'label' => 'apariencia.ajuste.'.$clave,
                'required' => false,
                'data' => $this->ajustes->valor($clave),
                'attr' => ['placeholder' => 'SITIO_TITULO_EN' === $clave ? $this->apariencia->titulo('en') : ('SITIO_TITULO' === $clave ? $this->apariencia->titulo('es') : $this->apariencia->institucion())],
                'constraints' => [new Assert\Length(max: 80)],
            ]);
        }
        foreach (Apariencia::COLORES as $clave => [$base]) {
            $constructor->add($clave, ColorType::class, [
                'label' => 'apariencia.ajuste.'.$clave,
                'help' => 'apariencia.ayuda.'.$clave,
                'data' => $this->apariencia->color($clave) ?? $base,
                'constraints' => [
                    new Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/'),
                    new Assert\Callback(fn (?string $color, ExecutionContextInterface $contexto) => $this->validarContraste($clave, $contexto)),
                ],
            ]);
        }
        $constructor
            ->add('logo', FileType::class, [
                'label' => 'apariencia.ajuste.LOGO',
                'help' => 'apariencia.ayuda.LOGO',
                'required' => false,
                'constraints' => [new Assert\Image(maxSize: '1M', mimeTypes: ['image/png', 'image/jpeg', 'image/webp'], maxWidth: 2000, maxHeight: 1000)],
            ])
            ->add('quitar_logo', CheckboxType::class, ['label' => 'apariencia.quitar_logo', 'required' => false])
            ->add('imagen_portada', FileType::class, [
                'label' => 'apariencia.ajuste.IMAGEN_PORTADA',
                'help' => 'apariencia.ayuda.IMAGEN_PORTADA',
                'required' => false,
                'constraints' => [new Assert\Image(maxSize: '3M', mimeTypes: ['image/png', 'image/jpeg', 'image/webp'], minWidth: 800, maxWidth: 4000, maxHeight: 3000)],
            ])
            ->add('quitar_imagen_portada', CheckboxType::class, ['label' => 'apariencia.quitar_imagen_portada', 'required' => false])
            ->add('restablecer', CheckboxType::class, ['label' => 'apariencia.restablecer', 'required' => false]);
        $formulario = $constructor->getForm();
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            $restablecer = (bool) $formulario->get('restablecer')->getData();
            foreach (Apariencia::COLORES as $clave => [$base]) {
                $color = strtolower((string) $formulario->get($clave)->getData());
                $this->ajustes->guardar($clave, $restablecer || $color === $base ? null : $color);
            }
            foreach (['SITIO_TITULO', 'SITIO_TITULO_EN', 'SITIO_INSTITUCION'] as $clave) {
                $this->ajustes->guardar($clave, trim((string) $formulario->get($clave)->getData()) ?: null);
            }
            $logo = $formulario->get('logo')->getData();
            if ($logo instanceof UploadedFile) {
                $this->apariencia->guardarLogo($logo);
            } elseif ($formulario->get('quitar_logo')->getData()) {
                $this->apariencia->quitarLogo();
            }
            $imagen = $formulario->get('imagen_portada')->getData();
            if ($imagen instanceof UploadedFile) {
                $this->apariencia->guardarImagenPortada($imagen);
            } elseif ($formulario->get('quitar_imagen_portada')->getData()) {
                $this->apariencia->quitarImagenPortada();
            }
            $this->addFlash('exito', 'apariencia.guardada');

            return $this->redirectToRoute('admin_apariencia');
        }

        return $this->render('admin/apariencia.html.twig', ['formulario' => $formulario], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }

    /**
     * Comprueba los pares de Apariencia::PARES en que participa el color (como texto o como fondo) con los demás
     * valores del formulario. «Volver al tema base» omite la comprobación.
     */
    private function validarContraste(string $clave, ExecutionContextInterface $contexto): void
    {
        $formulario = $contexto->getRoot();
        if (!$formulario instanceof FormInterface || $formulario->get('restablecer')->getData()) {
            return;
        }
        $valor = function (string $c) use ($formulario): ?string {
            if ('COLOR_PRINCIPAL_OSCURO' === $c) {
                $principal = $formulario->get('COLOR_PRINCIPAL')->getData();

                return \is_string($principal) && preg_match('/^#[0-9a-fA-F]{6}$/', $principal) ? Apariencia::oscuro($principal) : null;
            }
            $color = str_starts_with($c, '#') ? $c : $formulario->get($c)->getData();

            return \is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : null;
        };
        foreach (Apariencia::PARES as [$texto, $fondo]) {
            // El error se muestra en el campo de texto del par (o en el de fondo cuando el otro es un color fijo).
            if ($texto !== $clave) {
                continue;
            }
            [$a, $b] = [$valor($texto), $valor($fondo)];
            if (null !== $a && null !== $b && ($contraste = Apariencia::contraste($a, $b)) < Apariencia::CONTRASTE_MINIMO) {
                $contexto->buildViolation('apariencia.contraste_bajo')
                    ->setParameter('%contraste%', number_format($contraste, 1, ','))
                    ->setParameter('%fondo%', $this->traductor->trans('apariencia.fondo.'.(str_starts_with($fondo, '#') ? 'blanco' : $fondo)))
                    ->addViolation();

                return;
            }
        }
    }
}
