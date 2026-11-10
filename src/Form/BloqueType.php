<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Usuario;
use App\Enum\TipoBloque;
use App\Repository\UsuarioRepository;
use App\Servicio\VideoIncrustado;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulario de un bloque de guía. Trabaja con un arreglo (título + campos del tipo); el controlador lo pasa a
 * Bloque::setContenido() después de sanear el HTML y resolver los recursos elegidos.
 */
final class BloqueType extends AbstractType
{
    public function __construct(private readonly VideoIncrustado $video)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('titulo', TextType::class, [
            'label' => 'bloque.titulo',
            'required' => false,
            'help' => 'bloque.titulo_ayuda',
            'constraints' => [new Assert\Length(max: 150)],
        ]);

        match ($options['tipo']) {
            TipoBloque::Texto => $builder->add('html', TextareaType::class, [
                'label' => 'bloque.texto',
                'required' => false,
                'attr' => ['rows' => 12, 'data-controller' => 'editor-texto'],
                'constraints' => [new Assert\Length(max: 60000)],
            ]),
            // Lista JSON que arma el selector: [{"id": 4, "nombre": "Scopus"}, ...]
            TipoBloque::Recursos => $builder
                ->add('recursos', HiddenType::class, ['required' => false])
                ->add('descripcion', CheckboxType::class, ['label' => 'bloque.mostrar_descripcion', 'required' => false]),
            TipoBloque::Perfil => $builder->add('usuario', EntityType::class, [
                'label' => 'bloque.perfil',
                'class' => Usuario::class,
                'choice_label' => fn (Usuario $u) => $u->getNombreCompleto(),
                'query_builder' => fn (UsuarioRepository $r) => $r->personalActivo(),
                'placeholder' => 'bloque.elegir_persona',
                'constraints' => [new Assert\NotNull(message: 'bloque.perfil_obligatorio')],
            ]),
            TipoBloque::Video => $builder
                ->add('url', UrlType::class, [
                    'label' => 'bloque.video_url',
                    'default_protocol' => 'https',
                    'help' => 'bloque.video_url_ayuda',
                    'constraints' => [
                        new Assert\NotBlank(),
                        new Assert\Callback(function (?string $url, $contexto): void {
                            if (null !== $url && null === $this->video->direccion($url)) {
                                $contexto->buildViolation('bloque.video_no_valido')->addViolation();
                            }
                        }),
                    ],
                ])
                ->add('descripcion', TextareaType::class, [
                    'label' => 'bloque.video_descripcion',
                    'required' => false,
                    'attr' => ['rows' => 3],
                    'constraints' => [new Assert\Length(max: 1000)],
                ]),
            TipoBloque::Busqueda => $builder->add('texto', TextType::class, [
                'label' => 'bloque.busqueda_texto',
                'required' => false,
                'help' => 'bloque.busqueda_texto_ayuda',
                'constraints' => [new Assert\Length(max: 300)],
            ]),
            TipoBloque::Enlaces => $this->lista($builder, EnlaceBloqueType::class),
            TipoBloque::Libros => $this->libros($this->lista($builder, LibroBloqueType::class)),
            TipoBloque::Diapositivas => $this->lista($builder, DiapositivaBloqueType::class, 20),
        };
    }

    /** Lista de elementos (enlaces, libros, diapositivas) que se agregan, quitan y ordenan con el controlador "coleccion". */
    private function lista(FormBuilderInterface $builder, string $tipo, int $maximo = 50): FormBuilderInterface
    {
        return $builder->add('items', CollectionType::class, [
            'label' => false,
            'entry_type' => $tipo,
            'entry_options' => ['label' => false],
            'allow_add' => true,
            'allow_delete' => true,
            'delete_empty' => fn (?array $item) => null === $item || [] === array_filter($item, fn ($v) => null !== $v && '' !== $v),
            'prototype' => true,
            'error_bubbling' => false,
            'constraints' => [new Assert\Count(min: 1, max: $maximo, minMessage: 'bloque.lista_vacia')],
        ]);
    }

    /** Campo para agregar varios libros de una vez, pegando ISBN (uno por línea): cada uno se suma a la lista. */
    private function libros(FormBuilderInterface $builder): FormBuilderInterface
    {
        $builder
            ->add('presentacion', ChoiceType::class, [
                'label' => 'bloque.libros_presentacion',
                'expanded' => true,
                'choices' => ['bloque.libros_cuadricula' => 'cuadricula', 'bloque.libros_carrusel' => 'carrusel', 'bloque.libros_lista' => 'lista'],
                'constraints' => [new Assert\Choice(choices: ['cuadricula', 'carrusel', 'lista'])],
            ])
            ->add('columnas', ChoiceType::class, [
                'label' => 'bloque.libros_columnas',
                'help' => 'bloque.libros_columnas_ayuda',
                'choices' => ['bloque.libros_columnas_auto' => 0, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6],
                'choice_translation_domain' => 'messages',
                'constraints' => [new Assert\Choice(choices: [0, 2, 3, 4, 5, 6])],
            ]);
        $builder->add('isbns', TextareaType::class, [
            'label' => 'bloque.libros_isbns',
            'help' => 'bloque.libros_isbns_ayuda',
            'mapped' => false,
            'required' => false,
            'attr' => ['rows' => 4, 'spellcheck' => 'false'],
        ]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $evento): void {
            $datos = $evento->getData();
            if (!\is_array($datos)) {
                return;
            }
            $items = array_values(array_filter((array) ($datos['items'] ?? []), 'is_array'));
            $isbns = array_map(fn (string $t) => strtoupper(str_replace('-', '', $t)), preg_split('/[\s,;]+/', (string) ($datos['isbns'] ?? '')) ?: []);
            foreach (\array_slice(array_filter($isbns, fn (string $t) => 1 === preg_match('/^(\d{9}[\dX]|\d{13})$/', $t)), 0, 50) as $isbn) {
                $items[] = ['isbn' => $isbn, 'titulo' => '', 'autor' => '', 'enlace' => ''];
            }
            $datos['items'] = $items;
            $datos['isbns'] = '';
            $evento->setData($datos);
        });

        return $builder;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('tipo');
        $resolver->setAllowedTypes('tipo', TipoBloque::class);
    }
}
