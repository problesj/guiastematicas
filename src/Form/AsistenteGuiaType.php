<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Usuario;
use App\Repository\MateriaRepository;
use App\Repository\UsuarioRepository;
use App\Servicio\GeneradorSlug;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Datos que pide el asistente para armar una guía: título, idioma, materias (al menos una), carreras de Biblioges
 * para la bibliografía básica, coeditores y si se usa la IA. Opciones: carreras (código => nombre), ia (hay clave).
 */
final class AsistenteGuiaType extends AbstractType
{
    public function __construct(private readonly GeneradorSlug $slugs)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titulo', TextType::class, ['label' => 'guia.titulo', 'attr' => ['maxlength' => 200]])
            ->add('descripcion', TextareaType::class, ['label' => 'guia.descripcion', 'required' => false, 'help' => 'asistente.descripcion_ayuda', 'attr' => ['rows' => 2, 'maxlength' => 500]])
            ->add('idioma', ChoiceType::class, ['label' => 'guia.idioma', 'choices' => ['idioma.es' => 'es', 'idioma.en' => 'en']])
            ->add('materias', EntityType::class, [
                'label' => 'asistente.materias',
                'help' => 'asistente.materias_ayuda',
                'class' => Materia::class,
                'choice_label' => 'nombre',
                'query_builder' => fn (MateriaRepository $r) => $r->createQueryBuilder('m')->where('m.activa = true')->orderBy('m.nombre'),
                'multiple' => true,
                'expanded' => true,
                'by_reference' => false,
                'constraints' => [new Assert\Count(min: 1, minMessage: 'asistente.materias_obligatorias')],
            ])
            ->add('coeditores', EntityType::class, [
                'label' => 'guia.coeditores',
                'help' => 'asistente.coeditores_ayuda',
                'class' => Usuario::class,
                'choice_label' => fn (Usuario $u) => $u->getNombreCompleto(),
                'query_builder' => fn (UsuarioRepository $r) => $r->personalActivo(),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
            ]);
        if ([] !== $options['carreras']) {
            $builder->add('carreras', ChoiceType::class, [
                'label' => 'asistente.carreras',
                'help' => 'asistente.carreras_ayuda',
                'mapped' => false,
                'multiple' => true,
                'required' => false,
                'choices' => array_flip($options['carreras']),
                'choice_translation_domain' => false,
                'attr' => ['size' => 8],
                'constraints' => [new Assert\Count(max: 5, maxMessage: 'asistente.carreras_maximo')],
            ]);
        }
        $builder->add('usar_ia', CheckboxType::class, [
            'label' => 'asistente.usar_ia',
            'help' => $options['ia'] ? 'asistente.usar_ia_ayuda' : 'asistente.sin_clave',
            'mapped' => false,
            'required' => false,
            'data' => $options['ia'],
            'disabled' => !$options['ia'],
        ]);
        // La dirección se genera del título, como en «Nueva guía».
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $evento): void {
            $guia = $evento->getData();
            if ($guia instanceof Guia && '' === $guia->getSlug() && '' !== $guia->getTitulo()) {
                $guia->setSlug($this->slugs->generar(Guia::class, $guia->getTitulo()));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Guia::class, 'carreras' => [], 'ia' => false]);
        $resolver->setAllowedTypes('carreras', 'array');
        $resolver->setAllowedTypes('ia', 'bool');
    }
}
