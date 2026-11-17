<?php

declare(strict_types=1);

namespace App\Correo;

use App\Configuracion\Apariencia;
use App\Entity\Consulta;
use App\Entity\MensajeConsulta;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Correos de las consultas a bibliotecarios: aviso al bibliotecario, copia a la persona y respuestas.
 * Los textos están en templates/correo/consulta_*.html.twig (bloques asunto, html y texto).
 */
final class AvisosConsulta
{
    public function __construct(
        private readonly Cartero $cartero,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $rutas,
        private readonly Apariencia $apariencia,
    ) {
    }

    /** Consulta nueva: aviso al bibliotecario y copia a la persona. Marca el mensaje si el aviso salió. */
    public function nueva(MensajeConsulta $mensaje): void
    {
        $mensaje->setCorreoEnviado($this->alBibliotecario($mensaje, 'consulta_nueva'));
        $this->aLaPersona($mensaje, 'consulta_copia');
    }

    /** La persona respondió desde su enlace. */
    public function respuestaDePersona(MensajeConsulta $mensaje): void
    {
        $mensaje->setCorreoEnviado($this->alBibliotecario($mensaje, 'consulta_respuesta_persona'));
    }

    /** El bibliotecario respondió desde el panel. */
    public function respuestaDePersonal(MensajeConsulta $mensaje): void
    {
        $mensaje->setCorreoEnviado($this->aLaPersona($mensaje, 'consulta_respuesta'));
    }

    private function alBibliotecario(MensajeConsulta $mensaje, string $plantilla): bool
    {
        $consulta = $mensaje->getConsulta();
        $bibliotecario = $consulta->getBibliotecario();

        return $this->enviar(new Address($bibliotecario->getEmail(), $bibliotecario->getNombreCompleto()), $plantilla, 'es', $mensaje, [
            'enlace' => $this->rutas->generate('panel_consulta', ['_locale' => 'es', 'id' => $consulta->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    private function aLaPersona(MensajeConsulta $mensaje, string $plantilla): bool
    {
        $consulta = $mensaje->getConsulta();

        return $this->enviar(new Address($consulta->getCorreo(), $consulta->getNombre()), $plantilla, $consulta->getIdioma(), $mensaje, [
            'enlace' => $this->rutas->generate('consulta_ver', ['_locale' => $consulta->getIdioma(), 'token' => $consulta->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    private function enviar(Address $para, string $plantilla, string $idioma, MensajeConsulta $mensaje, array $datos): bool
    {
        $plantilla = $this->twig->load('correo/'.$plantilla.'.html.twig');
        $contexto = $datos + [
            'idioma' => $idioma,
            'consulta' => $mensaje->getConsulta(),
            'mensaje' => $mensaje,
            'sitio' => $this->apariencia->titulo($idioma),
        ];

        return $this->cartero->enviar((new Email())
            ->to($para)
            ->subject(trim($plantilla->renderBlock('asunto', $contexto)))
            ->html($plantilla->render($contexto))
            ->text(trim($plantilla->renderBlock('texto', $contexto))));
    }
}
