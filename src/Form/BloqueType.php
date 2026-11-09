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
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
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
                'query_builder' => fn (UsuarioRepository $r) => $r->createQueryBuilder('u')
                    ->where('u.activo = true')->orderBy('u.apellidos')->addOrderBy('u.nombre'),
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
        };
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('tipo');
        $resolver->setAllowedTypes('tipo', TipoBloque::class);
    }
}
