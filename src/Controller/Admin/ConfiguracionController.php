<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Configuracion\Ajustes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Integraciones administrables desde el panel: proxy AccesUM, catálogo (Primo VE y Alma), Biblioges, LDAP y Google.
 * Lo que queda vacío usa lo definido en el .env.local del servidor. Las claves se guardan cifradas y nunca se muestran.
 */
#[Route('/{_locale}/admin/configuracion', requirements: ['_locale' => 'es|en'])]
final class ConfiguracionController extends AbstractController
{
    /** Formato de los ajustes que son direcciones. */
    private const DIRECCIONES = [
        'PROXY_URL' => '#^https?://#i',
        'PRIMO_URL' => '#^https?://#i',
        'EXLIBRIS_API_URL' => '#^https?://#i',
        'BIBLIOGES_API_URL' => '#^https?://#i',
        'LDAP_URL' => '#^ldaps?://#i',
    ];

    public function __construct(private readonly Ajustes $ajustes)
    {
    }

    #[Route('', name: 'admin_configuracion', methods: ['GET', 'POST'])]
    public function editar(Request $request): Response
    {
        $formulario = $this->formulario();
        $formulario->handleRequest($request);

        if ($formulario->isSubmitted() && $formulario->isValid()) {
            foreach (Ajustes::GRUPOS as $ajustes) {
                foreach (array_keys($ajustes) as $clave) {
                    $valor = trim((string) $formulario->get($clave)->getData());
                    if (Ajustes::esSecreto($clave)) {
                        if ($formulario->get($clave.'__borrar')->getData()) {
                            $this->ajustes->guardar($clave, null);
                        } elseif ('' !== $valor) {
                            $this->ajustes->guardar($clave, $valor);
                        }
                    } elseif ($valor !== ($this->ajustes->valor($clave) ?? '')) {
                        $this->ajustes->guardar($clave, '' === $valor ? null : $valor);
                    }
                }
            }
            $this->addFlash('exito', 'configuracion.guardada');

            return $this->redirectToRoute('admin_configuracion');
        }

        $estado = [];
        foreach (Ajustes::GRUPOS as $ajustes) {
            foreach (array_keys($ajustes) as $clave) {
                $estado[$clave] = [
                    'panel' => $this->ajustes->enPanel($clave),
                    'ilegible' => $this->ajustes->ilegible($clave),
                    'servidor' => (string) ($_SERVER[$clave] ?? $_ENV[$clave] ?? ''),
                    'secreto' => Ajustes::esSecreto($clave),
                ];
            }
        }

        return $this->render('admin/configuracion.html.twig', [
            'formulario' => $formulario,
            'grupos' => array_map('array_keys', Ajustes::GRUPOS),
            'estado' => $estado,
        ], new Response(status: $formulario->isSubmitted() ? 422 : 200));
    }

    private function formulario(): FormInterface
    {
        $constructor = $this->createFormBuilder(null, ['translation_domain' => 'messages']);
        foreach (Ajustes::GRUPOS as $ajustes) {
            foreach ($ajustes as $clave => $opciones) {
                $etiqueta = 'configuracion.ajuste.'.$clave;
                if ($opciones['secreto'] ?? false) {
                    $constructor
                        ->add($clave, PasswordType::class, [
                            'label' => $etiqueta,
                            'required' => false,
                            'attr' => ['autocomplete' => 'new-password'],
                            'constraints' => [new Assert\Length(max: 500)],
                        ])
                        ->add($clave.'__borrar', CheckboxType::class, ['label' => 'configuracion.borrar_clave', 'required' => false]);
                } elseif (isset($opciones['opciones'])) {
                    $constructor->add($clave, ChoiceType::class, [
                        'label' => $etiqueta,
                        'required' => false,
                        'placeholder' => 'configuracion.usar_servidor',
                        'choices' => array_combine(array_map(fn ($o) => 'configuracion.opcion.'.$clave.'.'.$o, $opciones['opciones']), $opciones['opciones']),
                        'data' => $this->ajustes->valor($clave),
                    ]);
                } else {
                    $constructor->add($clave, TextType::class, [
                        'label' => $etiqueta,
                        'required' => false,
                        'data' => $this->ajustes->valor($clave),
                        'attr' => ['spellcheck' => 'false', 'autocomplete' => 'off'],
                        'constraints' => [
                            new Assert\Length(max: 500),
                            ...(isset(self::DIRECCIONES[$clave]) ? [new Assert\Regex(pattern: self::DIRECCIONES[$clave], message: 'configuracion.direccion_no_valida')] : []),
                        ],
                    ]);
                }
            }
        }

        return $constructor->getForm();
    }
}
