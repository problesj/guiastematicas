<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Materia;
use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Repository\MateriaRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RecursoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titulo', TextType::class, ['label' => 'recurso.titulo'])
            ->add('nombresAlternativos', TextType::class, ['label' => 'recurso.alternativos', 'required' => false, 'help' => 'recurso.alternativos_ayuda'])
            ->add('url', UrlType::class, ['label' => 'recurso.url', 'default_protocol' => 'https', 'help' => 'recurso.url_ayuda'])
            ->add('requiereProxy', CheckboxType::class, ['label' => 'recurso.requiere_proxy', 'required' => false, 'help' => 'recurso.requiere_proxy_ayuda'])
            ->add('tipo', EnumType::class, [
                'label' => 'recurso.tipo',
                'class' => TipoRecurso::class,
                'choice_label' => fn (TipoRecurso $t) => $t->etiqueta(),
            ])
            ->add('acceso', EnumType::class, [
                'label' => 'recurso.acceso',
                'class' => TipoAcceso::class,
                'choice_label' => fn (TipoAcceso $a) => $a->etiqueta(),
            ])
            ->add('pruebaHasta', DateType::class, [
                'label' => 'recurso.prueba_hasta',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'recurso.prueba_hasta_ayuda',
            ])
            ->add('proveedor', TextType::class, ['label' => 'recurso.proveedor', 'required' => false])
            ->add('descripcion', TextareaType::class, ['label' => 'recurso.descripcion', 'required' => false, 'attr' => ['rows' => 4]])
            ->add('descripcionEn', TextareaType::class, ['label' => 'recurso.descripcion_en', 'required' => false, 'attr' => ['rows' => 4]])
            ->add('materias', EntityType::class, [
                'label' => 'recurso.materias',
                'class' => Materia::class,
                'choice_label' => 'nombre',
                'query_builder' => fn (MateriaRepository $r) => $r->createQueryBuilder('m')->where('m.activa = true')->orderBy('m.nombre'),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('visible', CheckboxType::class, ['label' => 'recurso.visible', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Recurso::class]);
    }
}
