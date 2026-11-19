<?php

declare(strict_types=1);

namespace App\Twig;

use App\Clasificacion\Asjc;
use App\Entity\Materia;
use App\Form\MateriasType;
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

    /** Nombre de la materia para listas: «3308 · Derecho» si es una categoría ASJC, el nombre si es propia. */
    #[AsTwigFunction('materia_etiqueta')]
    public function etiqueta(Materia $materia): string
    {
        return MateriasType::etiqueta($materia, $this->requestStack->getCurrentRequest()?->getLocale() ?? 'es');
    }

    /**
     * Materias agrupadas por campo ASJC para un <select> con <optgroup> (en el orden de MateriaRepository::porCampo).
     *
     * @param iterable<Materia> $materias
     *
     * @return array<string, list<Materia>> nombre del campo => materias
     */
    #[AsTwigFunction('materias_por_campo')]
    public function porCampo(iterable $materias, string $sinClasificar = ''): array
    {
        $idioma = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'es';
        $lista = \App\Repository\MateriaRepository::ordenarPorCampo([...$materias]);
        $grupos = [];
        foreach ($lista as $materia) {
            $campo = $materia->getCampoAsjc();
            $grupos[$campo > 0 ? $campo.' · '.Asjc::nombreCampo($campo, $idioma) : $sinClasificar][] = $materia;
        }

        return $grupos;
    }
}
