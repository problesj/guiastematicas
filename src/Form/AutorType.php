<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Autor;
use App\Enum\Sexo;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AutorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombres', TextType::class, ['label' => 'autor.nombres', 'required' => false])
            ->add('apellidos', TextType::class, ['label' => 'autor.apellidos', 'help' => 'autor.apellidos_ayuda'])
            ->add('orcid', TextType::class, [
                'label' => 'autor.orcid',
                'required' => false,
                'help' => 'autor.orcid_ayuda',
                'attr' => ['placeholder' => '0000-0002-1825-0097', 'maxlength' => 40],
            ])
            ->add('sexo', EnumType::class, [
                'label' => 'autor.sexo',
                'class' => Sexo::class,
                'choice_label' => fn (Sexo $s) => $s->etiqueta(),
            ])
            ->add('pais', EntityType::class, PaisType::opcionesSelector('autor.pais'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Autor::class]);
    }
}
