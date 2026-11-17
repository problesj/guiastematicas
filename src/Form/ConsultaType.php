<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Consulta;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Formulario público «Escribir a…» de la ficha de un bibliotecario. */
final class ConsultaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombre', TextType::class, ['label' => 'consulta.nombre', 'attr' => ['autocomplete' => 'name', 'maxlength' => 120]])
            ->add('correo', EmailType::class, ['label' => 'consulta.correo', 'help' => 'consulta.correo_ayuda', 'attr' => ['autocomplete' => 'email', 'maxlength' => 180]])
            ->add('asunto', TextType::class, ['label' => 'consulta.asunto', 'attr' => ['maxlength' => 200]])
            ->add('mensaje', TextareaType::class, [
                'label' => 'consulta.mensaje',
                'mapped' => false,
                'attr' => ['rows' => 6, 'maxlength' => 5000],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 5000)],
            ])
            ->add('captcha', HiddenType::class, ['mapped' => false, 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Consulta::class, 'empty_data' => null]);
    }
}
