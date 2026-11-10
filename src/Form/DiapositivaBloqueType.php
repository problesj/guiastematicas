<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Una diapositiva del bloque "Diapositivas": imagen subida (o la ya guardada), texto y enlace opcionales. */
final class DiapositivaBloqueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('imagen', HiddenType::class, ['required' => false])
            ->add('archivo', FileType::class, [
                'label' => 'bloque.diapositiva_imagen',
                'required' => false,
                'help' => 'bloque.diapositiva_imagen_ayuda',
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [new Assert\Image(
                    maxSize: '4M',
                    mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    maxWidth: 6000,
                    maxHeight: 6000,
                )],
            ])
            ->add('titulo', TextType::class, [
                'label' => 'bloque.diapositiva_titulo',
                'required' => false,
                'constraints' => [new Assert\Length(max: 200)],
            ])
            ->add('enlace', UrlType::class, [
                'label' => 'bloque.diapositiva_enlace',
                'required' => false,
                'default_protocol' => 'https',
                'constraints' => [new Assert\Url(requireTld: true), new Assert\Length(max: 500)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('constraints', [new Assert\Callback(static function (mixed $datos, ExecutionContextInterface $contexto): void {
            if (\is_array($datos) && !($datos['archivo'] ?? null) instanceof UploadedFile && '' === (string) ($datos['imagen'] ?? '')) {
                $contexto->buildViolation('bloque.diapositiva_sin_imagen')->atPath('[archivo]')->addViolation();
            }
        })]);
    }
}
