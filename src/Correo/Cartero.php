<?php

declare(strict_types=1);

namespace App\Correo;

use App\Configuracion\Ajustes;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Envía correos con el servidor SMTP de Admin → Integraciones → Correo (o, si está vacío, el del .env.local).
 * CORREO_DSN en el servidor (p. ej. smtp://usuario:clave@host:587) reemplaza todo lo anterior.
 * Si no hay servidor configurado o el envío falla, devuelve false y lo anota en el registro: lo que se guarda
 * en la plataforma (consultas, sugerencias) no depende del correo.
 */
final class Cartero
{
    public function __construct(
        private readonly Ajustes $ajustes,
        #[Autowire(service: 'mailer.transport_factory')]
        private readonly Transport $transportes,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function configurado(): bool
    {
        return null !== $this->dsn() && null !== $this->remitente();
    }

    public function remitente(): ?Address
    {
        $correo = $this->leer('CORREO_REMITENTE');
        if (null === $correo || false === filter_var($correo, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return new Address($correo, $this->leer('CORREO_REMITENTE_NOMBRE') ?? '');
    }

    /** Envía el correo; el remitente es el configurado. true si el servidor lo aceptó. */
    public function enviar(Email $correo): bool
    {
        $dsn = $this->dsn();
        $remitente = $this->remitente();
        if (null === $dsn || null === $remitente) {
            $this->logger->notice('Correo no enviado: falta configurar el servidor SMTP o el remitente.', ['asunto' => $correo->getSubject()]);

            return false;
        }

        try {
            $this->transportes->fromString($dsn)->send($correo->from($remitente));

            return true;
        } catch (TransportExceptionInterface|\InvalidArgumentException $e) {
            $this->logger->error('No se pudo enviar el correo: {error}', ['error' => $e->getMessage(), 'asunto' => $correo->getSubject()]);

            return false;
        }
    }

    private function dsn(): ?string
    {
        $directo = $this->leer('CORREO_DSN');
        if (null !== $directo) {
            return $directo;
        }
        $host = $this->leer('CORREO_SMTP_HOST');
        if (null === $host) {
            return null;
        }
        $cifrado = $this->leer('CORREO_SMTP_CIFRADO') ?? 'tls';
        $puerto = (int) ($this->leer('CORREO_SMTP_PUERTO') ?? ('ssl' === $cifrado ? 465 : 587));
        $usuario = $this->leer('CORREO_SMTP_USUARIO');
        $credenciales = null === $usuario ? '' : rawurlencode($usuario).':'.rawurlencode($this->leer('CORREO_SMTP_CLAVE') ?? '').'@';

        return \sprintf('%s://%s%s:%d%s', 'ssl' === $cifrado ? 'smtps' : 'smtp', $credenciales, $host, $puerto, 'none' === $cifrado ? '?auto_tls=false' : '');
    }

    private function leer(string $clave): ?string
    {
        $valor = Ajustes::existe($clave) ? $this->ajustes->valor($clave) : null;
        $valor ??= $_SERVER[$clave] ?? $_ENV[$clave] ?? null;
        $valor = \is_string($valor) ? trim($valor) : '';

        return '' === $valor ? null : $valor;
    }
}
