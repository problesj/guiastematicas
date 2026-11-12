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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Nombre del sitio, logo y colores. Los colores deben tener contraste AA con el texto que llevan encima. */
#[Route('/{_locale}/admin/apariencia', requirements: ['_locale' => 'es|en'])]
final class AparienciaController extends AbstractController
{
    /** Colores del tema base (app.css), que se muestran cuando no hay uno propio. */
    private const BASE = ['COLOR_PRINCIPAL' => '#ac6d33', 'COLOR_BARRA' => '#7e9bc0', 'COLOR_PIE' => '#2b2b2a'];

    public function __construct(private readonly Ajustes $ajustes, private readonly Apariencia $apariencia)
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
        foreach (Apariencia::COLORES as $clave => $texto) {
            $constructor->add($clave, ColorType::class, [
                'label' => 'apariencia.ajuste.'.$clave,
                'help' => 'apariencia.ayuda.'.$clave,
                'data' => $this->apariencia->color($clave) ?? self::BASE[$clave],
                'constraints' => [
                    new Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/'),
                    new Assert\Callback(function (?string $color, ExecutionContextInterface $contexto) use ($texto, $clave): void {
                        // El color del tema base se acepta tal cual (su contraste se revisa en la auditoría de accesibilidad).
                        if (null !== $color && strtolower($color) !== self::BASE[$clave] && preg_match('/^#[0-9a-fA-F]{6}$/', $color) && Apariencia::contraste($color, $texto) < Apariencia::CONTRASTE_MINIMO) {
                            $contexto->buildViolation('apariencia.contraste_bajo')
                                ->setParameter('%contraste%', number_format(Apariencia::contraste($color, $texto), 1, ','))
                                ->addViolation();
                        }
                    }),
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
            ->add('restablecer', CheckboxType::class, ['label' => 'apariencia.restablecer', 'required' => false]);
        $formulario = $constructor->getForm();
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            if ($formulario->get('restablecer')->getData()) {
                foreach (array_keys(Apariencia::COLORES) as $clave) {
                    $this->ajustes->guardar($clave, null);
                }
            } else {
                foreach (array_keys(Apariencia::COLORES) as $clave) {
                    $color = strtolower((string) $formulario->get($clave)->getData());
                    $this->ajustes->guardar($clave, $color === self::BASE[$clave] ? null : $color);
                }
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
            $this->addFlash('exito', 'apariencia.guardada');

            return $this->redirectToRoute('admin_apariencia');
        }

        return $this->render('admin/apariencia.html.twig', ['formulario' => $formulario], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }
}
