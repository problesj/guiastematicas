<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Un enlace del bloque "Lista de enlaces". */
final class EnlaceBloqueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titulo', TextType::class, [
                'label' => 'bloque.enlace_titulo',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 200)],
            ])
            ->add('url', UrlType::class, [
                'label' => 'bloque.enlace_url',
                'default_protocol' => 'https',
                'constraints' => [new Assert\NotBlank(), new Assert\Url(requireTld: true), new Assert\Length(max: 500)],
            ])
            ->add('descripcion', TextType::class, [
                'label' => 'bloque.enlace_descripcion',
                'required' => false,
                'constraints' => [new Assert\Length(max: 300)],
            ]);
    }
}
