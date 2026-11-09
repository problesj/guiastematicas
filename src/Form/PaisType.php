<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Pais;
use App\Repository\PaisRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PaisType extends AbstractType
{
    /** Opciones de un EntityType para elegir país (autores, editoriales). */
    public static function opcionesSelector(string $etiqueta): array
    {
        return [
            'label' => $etiqueta,
            'class' => Pais::class,
            'choice_label' => 'nombre',
            'query_builder' => fn (PaisRepository $r) => $r->createQueryBuilder('p')->orderBy('p.nombre'),
            'required' => false,
            'placeholder' => 'formulario.sin_pais',
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('codigo', TextType::class, ['label' => 'pais.codigo', 'help' => 'pais.codigo_ayuda', 'attr' => ['maxlength' => 2, 'placeholder' => 'CL']])
            ->add('nombre', TextType::class, ['label' => 'pais.nombre'])
            ->add('nombreEn', TextType::class, ['label' => 'pais.nombre_en', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Pais::class]);
    }
}
