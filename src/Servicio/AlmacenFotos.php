<?php

declare(strict_types=1);

namespace App\Servicio;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Guarda las fotos de perfil en public/uploads/personal con un nombre aleatorio. */
final class AlmacenFotos
{
    public const string RUTA_PUBLICA = 'uploads/personal';

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/'.self::RUTA_PUBLICA)]
        private readonly string $directorio,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function guardar(UploadedFile $archivo, ?string $fotoAnterior = null): string
    {
        $extension = $archivo->guessExtension() ?? 'jpg';
        $nombre = bin2hex(random_bytes(12)).'.'.$extension;
        $this->filesystem->mkdir($this->directorio);
        $archivo->move($this->directorio, $nombre);
        $this->eliminar($fotoAnterior);

        return $nombre;
    }

    public function eliminar(?string $foto): void
    {
        if (null !== $foto && '' !== $foto && !str_contains($foto, '/')) {
            $this->filesystem->remove($this->directorio.'/'.$foto);
        }
    }
}
