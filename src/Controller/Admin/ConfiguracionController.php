<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Configuracion\Ajustes;
use App\Correo\Cartero;
use App\Entity\Usuario;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Integraciones administrables desde el panel: proxy AccesUM, catálogo (Primo VE y Alma), Biblioges, LDAP, Google, correo y Moodle.
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
        'LTI_ISSUER' => '#^https?://#i',
        'LTI_AUTH_URL' => '#^https?://#i',
        'LTI_JWKS_URL' => '#^https?://#i',
        'CORREO_REMITENTE' => '/^[^@\s]+@[^@\s]+\.[^@\s]+$/',
        'CORREO_SMTP_PUERTO' => '/^\d{1,5}$/',
    ];

    public function __construct(private readonly Ajustes $ajustes)
    {
    }

    /**
     * Plugin de WordPress (integraciones/wordpress/guias-ucn) en un .zip listo para subir, con la dirección de este
     * servidor ya puesta (servidor.php).
     */
    #[Route('/wordpress.zip', name: 'admin_configuracion_wordpress', methods: ['GET'])]
    public function pluginWordpress(#[Autowire('%kernel.project_dir%/integraciones/wordpress/guias-ucn')] string $origen): Response
    {
        $archivo = tempnam(sys_get_temp_dir(), 'guias-ucn');
        $zip = new \ZipArchive();
        if (false === $archivo || true !== $zip->open($archivo, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('No se pudo crear el paquete del plugin.');
        }
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($origen, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterador as $ruta => $info) {
            if ($info->isFile()) {
                $zip->addFile($ruta, 'guias-ucn/'.str_replace('\\', '/', substr($ruta, \strlen($origen) + 1)));
            }
        }
        $servidor = rtrim($this->generateUrl('raiz', [], UrlGeneratorInterface::ABSOLUTE_URL), '/');
        $zip->addFromString('guias-ucn/servidor.php', "<?php\n\n// Dirección de la plataforma de Guías temáticas desde la que se descargó el plugin.\nreturn ".var_export($servidor, true).";\n");
        $zip->close();

        $respuesta = new BinaryFileResponse($archivo);
        $respuesta->headers->set('Content-Type', 'application/zip');
        $respuesta->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'guias-ucn.zip');
        $respuesta->deleteFileAfterSend();

        return $respuesta;
    }

    /** Envía un correo de prueba a quien está conectado con la configuración vigente del grupo Correo. */
    #[Route('/probar-correo', name: 'admin_configuracion_probar_correo', methods: ['POST'])]
    public function probarCorreo(Request $request, Cartero $cartero, TranslatorInterface $traductor): Response
    {
        if (!$this->isCsrfTokenValid('probar-correo', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $usuario = $this->getUser();
        \assert($usuario instanceof Usuario);
        if (!$cartero->configurado()) {
            $this->addFlash('error', 'configuracion.correo.sin_configurar');
        } elseif ($cartero->enviar((new Email())->to($usuario->getEmail())->subject($traductor->trans('configuracion.correo.asunto_prueba'))->text($traductor->trans('configuracion.correo.texto_prueba')))) {
            $this->addFlash('exito', $traductor->trans('configuracion.correo.enviado', ['%correo%' => $usuario->getEmail()]));
        } else {
            $this->addFlash('error', 'configuracion.correo.fallo');
        }

        return $this->redirectToRoute('admin_configuracion');
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
