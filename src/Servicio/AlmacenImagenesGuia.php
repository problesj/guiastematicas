<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Entity\Guia;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Guarda el banner y el ícono de las guías en public/uploads/guias con un nombre aleatorio. */
final class AlmacenImagenesGuia
{
    public const string RUTA_PUBLICA = 'uploads/guias';

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/'.self::RUTA_PUBLICA)]
        private readonly string $directorio,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /** Aplica los campos archivoBanner/eliminarBanner y archivoIcono/eliminarIcono de un GuiaType ya validado. */
    public function aplicarFormulario(FormInterface $formulario, Guia $guia): void
    {
        $guia->setBanner($this->aplicar($formulario, 'Banner', $guia->getBanner()));
        $guia->setIcono($this->aplicar($formulario, 'Icono', $guia->getIcono()));
    }

    public function eliminarDe(Guia $guia): void
    {
        $this->eliminar($guia->getBanner());
        $this->eliminar($guia->getIcono());
    }

    private function aplicar(FormInterface $formulario, string $campo, ?string $actual): ?string
    {
        $archivo = $formulario->has('archivo'.$campo) ? $formulario->get('archivo'.$campo)->getData() : null;
        if ($archivo instanceof UploadedFile) {
            $nombre = bin2hex(random_bytes(12)).'.'.($archivo->guessExtension() ?? 'jpg');
            $this->filesystem->mkdir($this->directorio);
            $archivo->move($this->directorio, $nombre);
            $this->eliminar($actual);

            return $nombre;
        }
        if ($formulario->has('eliminar'.$campo) && true === $formulario->get('eliminar'.$campo)->getData()) {
            $this->eliminar($actual);

            return null;
        }

        return $actual;
    }

    private function eliminar(?string $archivo): void
    {
        if (null !== $archivo && '' !== $archivo && !str_contains($archivo, '/')) {
            $this->filesystem->remove($this->directorio.'/'.$archivo);
        }
    }
}
