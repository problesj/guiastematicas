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
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
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
            ->add('visible', CheckboxType::class, ['label' => 'recurso.visible', 'required' => false])
            // Datos bibliográficos: el formulario muestra solo los del tipo elegido (ver TipoRecurso::campos()).
            ->add('autores', TextType::class, ['label' => 'recurso.autores', 'required' => false, 'help' => 'recurso.autores_ayuda'])
            ->add('editorial', TextType::class, ['label' => 'recurso.editorial', 'required' => false])
            ->add('anio', IntegerType::class, ['label' => 'recurso.anio', 'required' => false, 'attr' => ['min' => 1450, 'max' => 2100]])
            ->add('isbn', TextType::class, ['label' => 'recurso.isbn', 'required' => false])
            ->add('eisbn', TextType::class, ['label' => 'recurso.eisbn', 'required' => false])
            ->add('issn', TextType::class, ['label' => 'recurso.issn', 'required' => false, 'attr' => ['placeholder' => '1234-5678']])
            ->add('eissn', TextType::class, ['label' => 'recurso.eissn', 'required' => false, 'attr' => ['placeholder' => '1234-5678']])
            ->add('disponibleDesde', DateType::class, [
                'label' => 'recurso.disponible_desde',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'recurso.disponible_desde_ayuda',
            ])
            ->add('codigoNorma', TextType::class, ['label' => 'recurso.codigo_norma', 'required' => false, 'attr' => ['placeholder' => 'NCh 433']])
            ->add('organismo', TextType::class, ['label' => 'recurso.organismo', 'required' => false, 'attr' => ['placeholder' => 'INN, ISO, IEEE']])
            ->addEventListener(FormEvents::SUBMIT, function (FormEvent $evento): void {
                $evento->getData()?->limpiarCamposNoAplicables();
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Recurso::class]);
    }
}
