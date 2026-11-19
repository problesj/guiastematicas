<?php

declare(strict_types=1);

namespace App\Form;

use App\Clasificacion\Asjc;
use App\Entity\Materia;
use App\Repository\MateriaRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Selector de materias organizado por el esquema ASJC: casillas agrupadas por campo en secciones desplegables (las
 * que tienen materias marcadas, abiertas), cada categoría seguida de las materias propias clasificadas en ella, y un
 * buscador por nombre o código (controlador filtro-asjc).
 */
final class MateriasType extends AbstractType
{
    public function __construct(
        private readonly RequestStack $peticiones,
        private readonly MateriaRepository $materias,
    ) {
    }

    private function idioma(): string
    {
        return $this->peticiones->getCurrentRequest()?->getLocale() ?? 'es';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Materia::class,
            'choices' => fn (Options $o) => $this->materias->porCampo(),
            'choice_label' => fn (Materia $m) => self::etiqueta($m, $this->idioma()),
            'choice_attr' => fn (Materia $m) => ['data-campo' => $m->getCampoAsjc()] + ($m->isBase() ? [] : ['data-propia' => 1]),
            'choice_translation_domain' => false,
            'multiple' => true,
            'expanded' => true,
            'required' => false,
        ]);
    }

    /** «3308 · Derecho» para las categorías ASJC; el nombre, para las materias propias. */
    public static function etiqueta(Materia $materia, string $idioma): string
    {
        return $materia->isBase() ? $materia->getCodigoAsjc().' · '.$materia->getNombreTraducido($idioma) : $materia->getNombreTraducido($idioma);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['campos'] = array_map(fn (array $d) => $d['nombre'], Asjc::porCampo($this->idioma()));
        $marcados = [];
        foreach ($form->getData() ?? [] as $materia) {
            if ($materia instanceof Materia) {
                $marcados[$materia->getCampoAsjc()] = ($marcados[$materia->getCampoAsjc()] ?? 0) + 1;
            }
        }
        $view->vars['marcados'] = $marcados;
    }

    public function getParent(): string
    {
        return EntityType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'materias_asjc';
    }
}
