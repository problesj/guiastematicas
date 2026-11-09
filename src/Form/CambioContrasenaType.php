<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

final class CambioContrasenaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('actual', PasswordType::class, [
                'label' => 'contrasena.actual',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new Assert\NotBlank(), new UserPassword(message: 'contrasena.actual_incorrecta')],
            ])
            ->add('nueva', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'contrasena.no_coinciden',
                'first_options' => ['label' => 'contrasena.nueva', 'help' => 'contrasena.nueva_ayuda', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'contrasena.repetir', 'attr' => ['autocomplete' => 'new-password']],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 12, max: 4096),
                    new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'contrasena.debil'),
                ],
            ]);
    }
}
