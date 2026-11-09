<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Usuario;
use App\Enum\DisposicionGuia;
use App\Enum\Rol;
use App\Repository\GuiaRepository;
use App\Repository\MateriaRepository;
use App\Repository\UsuarioRepository;
use App\Servicio\GeneradorSlug;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Datos generales de una guía. "administrador" habilita elegir el responsable. */
final class GuiaType extends AbstractType
{
    public function __construct(private readonly GeneradorSlug $slugs)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $guia = $builder->getData();
        $builder
            ->add('titulo', TextType::class, ['label' => 'guia.titulo'])
            ->add('slug', TextType::class, [
                'label' => 'guia.slug',
                'required' => false,
                'help' => 'guia.slug_ayuda',
                'empty_data' => '',
                'attr' => ['placeholder' => 'quimica-general'],
            ])
            ->add('descripcion', TextareaType::class, ['label' => 'guia.descripcion', 'required' => false, 'help' => 'guia.descripcion_ayuda', 'attr' => ['rows' => 3, 'maxlength' => 500]])
            ->add('idioma', ChoiceType::class, [
                'label' => 'guia.idioma',
                'choices' => ['idioma.es' => 'es', 'idioma.en' => 'en'],
            ])
            ->add('traduccion', EntityType::class, [
                'label' => 'guia.traduccion',
                'class' => Guia::class,
                'choice_label' => fn (Guia $g) => $g->getTitulo().' ('.strtoupper($g->getIdioma()).')',
                'query_builder' => fn (GuiaRepository $r) => $r->createQueryBuilder('g')
                    ->where('g.id <> :propia')->setParameter('propia', $guia instanceof Guia ? ($guia->getId() ?? 0) : 0)
                    ->orderBy('g.titulo'),
                'required' => false,
                'placeholder' => 'guia.sin_traduccion',
                'help' => 'guia.traduccion_ayuda',
            ])
            ->add('disposicion', EnumType::class, [
                'label' => 'guia.disposicion',
                'class' => DisposicionGuia::class,
                'choice_label' => fn (DisposicionGuia $d) => $d->etiqueta(),
                'expanded' => true,
            ])
            ->add('materias', EntityType::class, [
                'label' => 'guia.materias',
                'class' => Materia::class,
                'choice_label' => 'nombre',
                'query_builder' => fn (MateriaRepository $r) => $r->createQueryBuilder('m')->where('m.activa = true')->orderBy('m.nombre'),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->add('coeditores', EntityType::class, [
                'label' => 'guia.coeditores',
                'class' => Usuario::class,
                'choice_label' => fn (Usuario $u) => $u->getNombreCompleto().' ('.$u->getEmail().')',
                'query_builder' => fn (UsuarioRepository $r) => $r->createQueryBuilder('u')->where('u.activo = true')->orderBy('u.apellidos')->addOrderBy('u.nombre'),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
                'help' => 'guia.coeditores_ayuda',
            ]);

        // Imágenes: no se mapean; el controlador las guarda con AlmacenImagenesGuia. Sin SVG (podría llevar scripts).
        $guia = $options['data'] ?? null;
        foreach (['Banner' => ['4M', 6000, 'guia.banner_ayuda'], 'Icono' => ['1M', 2000, 'guia.icono_ayuda']] as $campo => [$tamano, $maximo, $ayuda]) {
            $builder->add('archivo'.$campo, FileType::class, [
                'label' => 'guia.'.strtolower($campo),
                'mapped' => false,
                'required' => false,
                'help' => $ayuda,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [new Assert\Image(
                    maxSize: $tamano,
                    mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    maxWidth: $maximo,
                    maxHeight: $maximo,
                )],
            ]);
            if ($guia instanceof Guia && null !== $guia->{'get'.$campo}()) {
                $builder->add('eliminar'.$campo, CheckboxType::class, ['label' => 'guia.eliminar_'.strtolower($campo), 'mapped' => false, 'required' => false]);
            }
        }

        // Sin dirección escrita, se arma con el título ("Química general" → quimica-general).
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $evento): void {
            $guia = $evento->getData();
            if ($guia instanceof Guia && '' === $guia->getSlug() && '' !== $guia->getTitulo()) {
                $guia->setSlug($this->slugs->generar(Guia::class, $guia->getTitulo(), $guia->getId()));
            }
        });

        if ($options['administrador']) {
            $builder->add('responsable', EntityType::class, [
                'label' => 'guia.responsable',
                'class' => Usuario::class,
                'choice_label' => fn (Usuario $u) => $u->getNombreCompleto().' ('.$u->getEmail().')',
                'query_builder' => fn (UsuarioRepository $r) => $r->createQueryBuilder('u')
                    ->where('u.activo = true')->andWhere('u.rol IN (:roles)')
                    ->setParameter('roles', [Rol::Editor->value, Rol::Administrador->value])
                    ->orderBy('u.apellidos')->addOrderBy('u.nombre'),
                'required' => false,
                'placeholder' => 'guia.sin_responsable',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Guia::class, 'administrador' => false]);
        $resolver->setAllowedTypes('administrador', 'bool');
    }
}
