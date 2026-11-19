<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Sugerencia;
use App\Enum\FormatoRecurso;
use App\Enum\TipoRecurso;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulario público para sugerir un recurso bibliográfico. */
final class SugerenciaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tipo', EnumType::class, [
                'label' => 'sugerencia.tipo',
                'class' => TipoRecurso::class,
                'choice_label' => fn (TipoRecurso $t) => $t->etiqueta(),
            ])
            ->add('formato', EnumType::class, [
                'label' => 'formato_recurso.etiqueta',
                'class' => FormatoRecurso::class,
                'choice_label' => fn (FormatoRecurso $f) => $f->etiqueta(),
                'required' => false,
                'placeholder' => 'formato_recurso.no_se',
            ])
            ->add('titulo', TextType::class, ['label' => 'sugerencia.titulo', 'attr' => ['maxlength' => 255]])
            ->add('autores', TextType::class, ['label' => 'sugerencia.autores', 'required' => false, 'help' => 'sugerencia.autores_ayuda', 'attr' => ['maxlength' => 255]])
            ->add('identificador', TextType::class, ['label' => 'sugerencia.identificador', 'required' => false, 'help' => 'sugerencia.identificador_ayuda', 'attr' => ['maxlength' => 30, 'inputmode' => 'numeric']])
            ->add('url', UrlType::class, ['label' => 'sugerencia.url', 'required' => false, 'default_protocol' => 'https', 'help' => 'sugerencia.url_ayuda'])
            ->add('descripcion', TextareaType::class, ['label' => 'sugerencia.descripcion', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 2000]])
            ->add('materias', MateriasType::class, [
                'label' => 'sugerencia.materias',
                'help' => 'sugerencia.materias_ayuda',
            ])
            ->add('motivo', TextareaType::class, ['label' => 'sugerencia.motivo', 'required' => false, 'help' => 'sugerencia.motivo_ayuda', 'attr' => ['rows' => 3, 'maxlength' => 2000]])
            ->add('nombre', TextType::class, ['label' => 'sugerencia.nombre', 'attr' => ['autocomplete' => 'name', 'maxlength' => 120]])
            ->add('correo', EmailType::class, ['label' => 'sugerencia.correo', 'help' => 'sugerencia.correo_ayuda', 'attr' => ['autocomplete' => 'email', 'maxlength' => 180]])
            ->add('captcha', HiddenType::class, ['mapped' => false, 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Sugerencia::class, 'idioma' => 'es']);
    }
}
