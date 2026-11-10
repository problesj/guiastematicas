<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Un libro del bloque "Libros": la portada se obtiene de Open Library por ISBN. */
final class LibroBloqueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titulo', TextType::class, [
                'label' => 'bloque.libro_titulo',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 300)],
            ])
            ->add('autor', TextType::class, [
                'label' => 'bloque.libro_autor',
                'required' => false,
                'constraints' => [new Assert\Length(max: 200)],
            ])
            ->add('isbn', TextType::class, [
                'label' => 'ISBN',
                'required' => false,
                'constraints' => [new Assert\Isbn(message: 'bloque.isbn_no_valido')],
            ])
            ->add('enlace', UrlType::class, [
                'label' => 'bloque.libro_enlace',
                'required' => false,
                'default_protocol' => 'https',
                'constraints' => [new Assert\Url(requireTld: true), new Assert\Length(max: 500)],
            ]);
    }
}
