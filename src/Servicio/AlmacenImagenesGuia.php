<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Enum\TipoBloque;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Guarda el banner, el ícono y las imágenes de las diapositivas de las guías en public/uploads/guias con un nombre
 * aleatorio. Las diapositivas llevan el prefijo "d{id de la guía}-" para poder borrar las que ya no se usan.
 */
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
        $this->podarDiapositivas($guia, []);
    }

    public function guardarDiapositiva(Guia $guia, UploadedFile $archivo): string
    {
        $nombre = 'd'.$guia->getId().'-'.bin2hex(random_bytes(12)).'.'.($archivo->guessExtension() ?? 'jpg');
        $this->filesystem->mkdir($this->directorio);
        $archivo->move($this->directorio, $nombre);

        return $nombre;
    }

    /** ¿Es el nombre de una imagen de diapositiva de esta guía? (el campo oculto llega del navegador) */
    public function esDiapositivaDe(Guia $guia, string $nombre): bool
    {
        return 1 === preg_match('/^d'.$guia->getId().'-[a-f0-9]{24}\.(jpg|jpeg|png|webp)$/', $nombre)
            && is_file($this->directorio.'/'.$nombre);
    }

    /**
     * Prepara el paso de un bloque de diapositivas a otra guía: cambia en el bloque el prefijo "d{id}-" de sus
     * imágenes por el de la guía de destino (si no, la poda de una u otra guía las borraría) y devuelve los
     * cambios de nombre pendientes, que se aplican con renombrar() una vez guardado el bloque.
     *
     * @return array<string, string> nombre actual => nombre nuevo
     */
    public function prepararTraslado(Bloque $bloque, Guia $origen, Guia $destino): array
    {
        if (TipoBloque::Diapositivas !== $bloque->getTipo() || $origen === $destino || null === $destino->getId()) {
            return [];
        }
        $renombres = [];
        $items = $bloque->dato('items', []);
        foreach ($items as $i => $item) {
            $imagen = (string) ($item['imagen'] ?? '');
            if ($this->esDiapositivaDe($origen, $imagen)) {
                $nuevo = 'd'.$destino->getId().'-'.substr($imagen, \strlen('d'.$origen->getId().'-'));
                $renombres[$imagen] = $nuevo;
                $items[$i]['imagen'] = $nuevo;
            }
        }
        if ([] !== $renombres) {
            $bloque->setContenido(['items' => $items] + $bloque->getContenido());
        }

        return $renombres;
    }

    /** @param array<string, string> $renombres nombre actual => nombre nuevo (de prepararTraslado) */
    public function renombrar(array $renombres): void
    {
        foreach ($renombres as $actual => $nuevo) {
            if (is_file($this->directorio.'/'.$actual)) {
                $this->filesystem->rename($this->directorio.'/'.$actual, $this->directorio.'/'.$nuevo, true);
            }
        }
    }

    /**
     * Borra las imágenes de diapositivas de la guía que ya no usa ningún bloque.
     *
     * @param list<string>|null $enUso null = las de los bloques actuales de la guía
     */
    public function podarDiapositivas(Guia $guia, ?array $enUso = null): void
    {
        if (null === $guia->getId()) {
            return;
        }
        if (null === $enUso) {
            $enUso = [];
            foreach ($guia->getPestanas() as $pestana) {
                foreach ($pestana->getSecciones() as $seccion) {
                    foreach ($seccion->getBloques() as $bloque) {
                        if (TipoBloque::Diapositivas === $bloque->getTipo()) {
                            array_push($enUso, ...array_column($bloque->dato('items', []), 'imagen'));
                        }
                    }
                }
            }
        }
        foreach (glob($this->directorio.'/d'.$guia->getId().'-*') ?: [] as $ruta) {
            if (!\in_array(basename($ruta), $enUso, true)) {
                $this->filesystem->remove($ruta);
            }
        }
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
