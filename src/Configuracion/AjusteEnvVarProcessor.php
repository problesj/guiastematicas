<?php

declare(strict_types=1);

namespace App\Configuracion;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * %env(ajuste:NOMBRE)%: el valor definido en el panel (App\Configuracion\Ajustes) o, si no lo está, la variable de
 * entorno NOMBRE. Se usa en todos los servicios de integraciones, así el panel y el .env.local conviven.
 */
final class AjusteEnvVarProcessor implements EnvVarProcessorInterface
{
    public function __construct(private readonly Ajustes $ajustes)
    {
    }

    public function getEnv(string $prefix, string $name, \Closure $getEnv): mixed
    {
        return Ajustes::existe($name) ? ($this->ajustes->valor($name) ?? $getEnv($name)) : $getEnv($name);
    }

    public static function getProvidedTypes(): array
    {
        return ['ajuste' => 'string'];
    }
}
