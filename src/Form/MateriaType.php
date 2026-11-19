<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Materia;
use App\Servicio\GeneradorSlug;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class MateriaType extends AbstractType
{
    public function __construct(private readonly GeneradorSlug $slugs)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombre', TextType::class, ['label' => 'materia.nombre'])
            ->add('nombreEn', TextType::class, ['label' => 'materia.nombre_en', 'required' => false])
            ->add('slug', TextType::class, ['label' => 'materia.slug', 'required' => false, 'help' => 'materia.slug_ayuda', 'empty_data' => ''])
            ->add('codigo', TextType::class, ['label' => 'materia.codigo', 'required' => false])
            ->add('activa', CheckboxType::class, ['label' => 'materia.activa', 'required' => false])
            // Las materias propias se clasifican en una o más categorías ASJC; las categorías ASJC ya lo son.
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $evento): void {
                $materia = $evento->getData();
                if (!$materia instanceof Materia || !$materia->isBase()) {
                    $evento->getForm()->add('asjc', AsjcType::class, [
                        'label' => 'materia.asjc',
                        'help' => 'materia.asjc_ayuda',
                        'required' => true,
                        'constraints' => [new Assert\Count(min: 1, minMessage: 'materia.asjc_obligatoria')],
                    ]);
                }
            })
            // Si el identificador queda vacío, se genera desde el nombre antes de validar.
            ->addEventListener(FormEvents::SUBMIT, function (FormEvent $evento): void {
                $materia = $evento->getData();
                if ($materia instanceof Materia && '' === $materia->getSlug() && '' !== $materia->getNombre()) {
                    $materia->setSlug($this->slugs->generar(Materia::class, $materia->getNombre(), $materia->getId()));
                }
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Materia::class]);
    }
}
