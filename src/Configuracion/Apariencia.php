<?php

declare(strict_types=1);

namespace App\Configuracion;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Nombre del sitio, logo, imagen de la portada y colores (de fondo y de texto) definidos en Administración → Apariencia. Lo que no se define usa el tema base
 * (colores de la UCN en app.css y los textos de las traducciones).
 */
final class Apariencia
{
    public const string RUTA_PUBLICA = 'uploads/sitio';

    /** Contraste mínimo (WCAG 2.1 AA, texto normal). */
    public const float CONTRASTE_MINIMO = 4.5;

    /**
     * Colores personalizables: valor del tema base (app.css) y variable CSS que reemplazan. Los de fondo (COLOR_*) y los
     * de texto (TEXTO_*) se comprueban en pares, ver PARES.
     */
    public const array COLORES = [
        'COLOR_PRINCIPAL' => ['#9a602c', '--color-principal'],
        'COLOR_BARRA' => ['#7e9bc0', '--color-azul'],
        'COLOR_PIE' => ['#2b2b2a', '--color-pie'],
        'TEXTO_GENERAL' => ['#1d1d1b', '--color-texto'],
        'TEXTO_TITULOS' => ['#1d1d1b', '--color-texto-titulos'],
        'TEXTO_CABECERA' => ['#1d1d1b', '--color-texto-cabecera'],
        'TEXTO_BARRA' => ['#1d1d1b', '--color-texto-barra'],
        'TEXTO_BOTON' => ['#ffffff', '--color-texto-boton'],
        'TEXTO_PIE' => ['#f2f2f2', '--color-texto-pie'],
    ];

    /**
     * Pares texto / fondo que deben tener contraste AA. El fondo es otra clave de COLORES o un color fijo (#ffffff: el
     * fondo blanco de las páginas y de la cabecera). El color principal también es el de los enlaces sobre blanco, y
     * el texto de los botones se comprueba además sobre el tono oscuro del principal (encabezados de los bloques).
     */
    public const array PARES = [
        ['COLOR_PRINCIPAL', '#ffffff'],
        ['TEXTO_GENERAL', '#ffffff'],
        ['TEXTO_TITULOS', '#ffffff'],
        ['TEXTO_CABECERA', '#ffffff'],
        ['TEXTO_BARRA', 'COLOR_BARRA'],
        ['TEXTO_BOTON', 'COLOR_PRINCIPAL'],
        ['TEXTO_BOTON', 'COLOR_PRINCIPAL_OSCURO'],
        ['TEXTO_PIE', 'COLOR_PIE'],
    ];

    public function __construct(
        private readonly Ajustes $ajustes,
        private readonly TranslatorInterface $traductor,
        #[Autowire('%kernel.project_dir%/public/'.self::RUTA_PUBLICA)]
        private readonly string $directorio,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    #[AsTwigFunction('sitio_titulo')]
    public function titulo(?string $idioma = null): string
    {
        $idioma ??= $this->traductor->getLocale();

        return ('en' === $idioma ? $this->ajustes->valor('SITIO_TITULO_EN') : null)
            ?? $this->ajustes->valor('SITIO_TITULO')
            ?? $this->traductor->trans('sitio.titulo', locale: $idioma);
    }

    #[AsTwigFunction('sitio_institucion')]
    public function institucion(): string
    {
        return $this->ajustes->valor('SITIO_INSTITUCION') ?? $this->traductor->trans('sitio.institucion');
    }

    /** Nombre del archivo del logo en public/uploads/sitio, o null. */
    #[AsTwigFunction('sitio_logo')]
    public function logo(): ?string
    {
        $logo = $this->ajustes->valor('LOGO');

        return null !== $logo && preg_match('/^logo-[a-f0-9]{16}\.(png|jpe?g|webp)$/', $logo) ? self::RUTA_PUBLICA.'/'.$logo : null;
    }

    /** Imagen de fondo de la portada en public/uploads/sitio, o null (se usa un degradado con los colores del sitio). */
    #[AsTwigFunction('sitio_imagen_portada')]
    public function imagenPortada(): ?string
    {
        $imagen = $this->ajustes->valor('IMAGEN_PORTADA');

        return null !== $imagen && preg_match('/^portada-[a-f0-9]{16}\.(png|jpe?g|webp)$/', $imagen) ? self::RUTA_PUBLICA.'/'.$imagen : null;
    }

    /**
     * Variables CSS que reemplazan a las del tema base (vacío si no hay colores propios). Los tonos oscuro y suave se
     * derivan del color principal.
     */
    #[AsTwigFunction('sitio_css')]
    public function css(): string
    {
        $variables = [];
        foreach (self::COLORES as $clave => [, $variable]) {
            if (null !== $color = $this->color($clave)) {
                $variables[$variable] = $color;
            }
        }
        if (null !== $principal = $this->color('COLOR_PRINCIPAL')) {
            $variables['--color-principal-oscuro'] = 'color-mix(in srgb, '.$principal.' 80%, #000)';
            $variables['--color-principal-suave'] = 'color-mix(in srgb, '.$principal.' 60%, #fff)';
            $variables['--color-fondo-suave'] = 'color-mix(in srgb, '.$principal.' 8%, #fff)';
        }

        return [] === $variables ? '' : 'html:root{'.implode('', array_map(fn ($k, $v) => $k.':'.$v.';', array_keys($variables), $variables)).'}';
    }

    public function color(string $clave): ?string
    {
        $color = $this->ajustes->valor($clave);

        return null !== $color && preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : null;
    }

    /** Guarda el logo con un nombre aleatorio y borra el anterior. */
    public function guardarLogo(UploadedFile $archivo): void
    {
        $this->guardarImagen($archivo, 'logo', 'LOGO', $this->logo());
    }

    public function quitarLogo(): void
    {
        $this->quitarImagen('LOGO', $this->logo());
    }

    /** Guarda la imagen de fondo de la portada con un nombre aleatorio y borra la anterior. */
    public function guardarImagenPortada(UploadedFile $archivo): void
    {
        $this->guardarImagen($archivo, 'portada', 'IMAGEN_PORTADA', $this->imagenPortada());
    }

    public function quitarImagenPortada(): void
    {
        $this->quitarImagen('IMAGEN_PORTADA', $this->imagenPortada());
    }

    private function guardarImagen(UploadedFile $archivo, string $prefijo, string $clave, ?string $anterior): void
    {
        $extension = $archivo->guessExtension() ?? 'png';
        $nombre = $prefijo.'-'.bin2hex(random_bytes(8)).'.'.('jpeg' === $extension ? 'jpg' : $extension);
        $this->filesystem->mkdir($this->directorio);
        $archivo->move($this->directorio, $nombre);
        $this->quitarImagen($clave, $anterior);
        $this->ajustes->guardar($clave, $nombre);
    }

    private function quitarImagen(string $clave, ?string $actual): void
    {
        if (null !== $actual) {
            $this->filesystem->remove($this->directorio.'/'.basename($actual));
        }
        $this->ajustes->guardar($clave, null);
    }

    /** Tono oscuro del color principal, como lo calcula color-mix(in srgb, principal 80%, #000). */
    public static function oscuro(string $color): string
    {
        return '#'.implode('', array_map(fn (string $c): string => \sprintf('%02x', (int) round(hexdec($c) * 0.8)), str_split(ltrim($color, '#'), 2)));
    }

    /** Relación de contraste WCAG entre dos colores #rrggbb. */
    public static function contraste(string $a, string $b): float
    {
        $luminancia = function (string $hex): float {
            $canales = array_map(function (string $c): float {
                $c = hexdec($c) / 255;

                return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));

            return 0.2126 * $canales[0] + 0.7152 * $canales[1] + 0.0722 * $canales[2];
        };
        [$claro, $oscuro] = [max($luminancia($a), $luminancia($b)), min($luminancia($a), $luminancia($b))];

        return ($claro + 0.05) / ($oscuro + 0.05);
    }
}
