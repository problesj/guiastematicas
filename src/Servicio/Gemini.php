<?php

declare(strict_types=1);

namespace App\Servicio;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente mínimo de la API de Gemini (Google AI Studio): envía un texto y pide la respuesta en JSON.
 *
 * La clave (GEMINI_API_KEY) va en el .env.local del servidor o en Admin → Configuración, donde se guarda cifrada;
 * viaja en la cabecera x-goog-api-key, nunca en la dirección. Sin clave, o si Gemini no responde, devuelve null y
 * quien lo usa sigue sin la IA.
 */
final class Gemini
{
    public const string MODELO_POR_DEFECTO = 'gemini-2.5-flash';
    private const string API = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const float ESPERA = 90.0; // segundos

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:default::ajuste:GEMINI_API_KEY)%')]
        private readonly string $clave = '',
        #[Autowire('%env(string:default::ajuste:GEMINI_MODELO)%')]
        private readonly string $modelo = '',
    ) {
    }

    public function disponible(): bool
    {
        return '' !== $this->clave;
    }

    public function modelo(): string
    {
        $modelo = trim($this->modelo);

        return 1 === preg_match('/^[a-z0-9][a-z0-9.\-]{1,60}$/', $modelo) ? $modelo : self::MODELO_POR_DEFECTO;
    }

    /**
     * Pide a Gemini una respuesta JSON para las instrucciones dadas.
     *
     * @return array<mixed>|null el JSON decodificado, o null si no hay clave, no respondió o la respuesta no es JSON
     */
    public function json(string $instrucciones, string $sistema = ''): ?array
    {
        if (!$this->disponible()) {
            return null;
        }
        try {
            $respuesta = $this->http->request('POST', self::API.rawurlencode($this->modelo()).':generateContent', [
                'headers' => ['x-goog-api-key' => $this->clave],
                'timeout' => self::ESPERA,
                'max_duration' => self::ESPERA,
                'json' => array_filter([
                    'systemInstruction' => '' === $sistema ? null : ['parts' => [['text' => $sistema]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $instrucciones]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.4],
                ]),
            ]);
            $estado = $respuesta->getStatusCode();
            if (200 !== $estado) {
                $this->logger->warning('Gemini respondió {estado}.', ['estado' => $estado]);

                return null;
            }
            $datos = $respuesta->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Gemini no respondió: {mensaje}', ['mensaje' => $e->getMessage()]);

            return null;
        }
        $texto = '';
        foreach ((array) ($datos['candidates'][0]['content']['parts'] ?? []) as $parte) {
            $texto .= \is_array($parte) ? (string) ($parte['text'] ?? '') : '';
        }
        // Por si viene envuelto en un bloque de código.
        $texto = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($texto)));
        $json = json_decode($texto, true);

        return \is_array($json) ? $json : null;
    }
}
