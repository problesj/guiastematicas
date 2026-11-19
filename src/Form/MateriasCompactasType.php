<?php

declare(strict_types=1);

namespace App\Form;

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
 * Selector compacto de materias para listas largas (un libro o revista por fila): muestra solo las marcadas y un
 * buscador que agrega otras desde el catálogo de materias (controlador selector-materias). Así no se repiten cientos
 * de casillas por cada fila.
 */
final class MateriasCompactasType extends AbstractType
{
    public function __construct(
        private readonly RequestStack $peticiones,
        private readonly MateriaRepository $materias,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Materia::class,
            'choices' => fn (Options $o) => $this->materias->porCampo(),
            'choice_label' => fn (Materia $m) => MateriasType::etiqueta($m, $this->peticiones->getCurrentRequest()?->getLocale() ?? 'es'),
            'choice_translation_domain' => false,
            'multiple' => true,
            'expanded' => false,
            'required' => false,
        ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $idioma = $this->peticiones->getCurrentRequest()?->getLocale() ?? 'es';
        $datos = $form->getData() ?? [];
        $datos = $datos instanceof \Traversable ? iterator_to_array($datos) : (array) $datos;
        $view->vars['marcadas'] = array_map(
            fn (Materia $m) => ['id' => $m->getId(), 'etiqueta' => MateriasType::etiqueta($m, $idioma)],
            array_values(array_filter($datos, fn ($m) => $m instanceof Materia)),
        );
    }

    public function getParent(): string
    {
        return EntityType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'materias_compactas';
    }
}
