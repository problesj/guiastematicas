<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Departamento;
use App\Enum\Sede;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DepartamentoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombre', TextType::class, ['label' => 'departamento.nombre'])
            ->add('nombreEn', TextType::class, ['label' => 'departamento.nombre_en', 'required' => false])
            ->add('sede', EnumType::class, [
                'label' => 'departamento.sede',
                'class' => Sede::class,
                'choice_label' => fn (Sede $sede) => $sede->etiqueta(),
                'required' => false,
                'placeholder' => 'formulario.ambas_sedes',
            ])
            ->add('email', EmailType::class, ['label' => 'departamento.email', 'required' => false])
            ->add('telefono', TelType::class, ['label' => 'departamento.telefono', 'required' => false])
            ->add('orden', IntegerType::class, ['label' => 'departamento.orden', 'help' => 'departamento.orden_ayuda']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Departamento::class]);
    }
}
