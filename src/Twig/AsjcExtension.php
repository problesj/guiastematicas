<?php

declare(strict_types=1);

namespace App\Twig;

use App\Clasificacion\Asjc;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/** Nombres de las categorías ASJC de Scopus en el idioma de la página. */
final class AsjcExtension
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    #[AsTwigFunction('asjc_nombre')]
    public function nombre(int $codigo): string
    {
        $idioma = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'es';

        return Asjc::existe($codigo) ? Asjc::nombre($codigo, $idioma) : (string) $codigo;
    }
}
