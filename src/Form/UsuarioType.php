<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Departamento;
use App\Entity\Materia;
use App\Entity\Usuario;
use App\Enum\Rol;
use App\Enum\Sede;
use App\Repository\DepartamentoRepository;
use App\Repository\MateriaRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Datos de una persona. En modo "admin" (administración de usuarios) incluye correo, rol, métodos de acceso y estado;
 * en modo "perfil" (cada persona edita el suyo) solo los datos que se muestran en el directorio.
 */
final class UsuarioType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $admin = 'admin' === $options['modo'];

        if ($admin) {
            $builder
                ->add('email', EmailType::class, ['label' => 'usuario.email'])
                ->add('rol', EnumType::class, [
                    'label' => 'usuario.rol',
                    'class' => Rol::class,
                    'choice_label' => fn (Rol $rol) => $rol->etiqueta(),
                    'help' => 'usuario.rol_ayuda',
                ]);
        }

        $builder
            ->add('nombre', TextType::class, ['label' => 'usuario.nombre'])
            ->add('apellidos', TextType::class, ['label' => 'usuario.apellidos'])
            ->add('cargo', TextType::class, ['label' => 'usuario.cargo', 'required' => false])
            ->add('cargoEn', TextType::class, ['label' => 'usuario.cargo_en', 'required' => false])
            ->add('departamento', EntityType::class, [
                'label' => 'usuario.departamento',
                'class' => Departamento::class,
                'choice_label' => 'nombre',
                'query_builder' => fn (DepartamentoRepository $r) => $r->createQueryBuilder('d')->orderBy('d.orden')->addOrderBy('d.nombre'),
                'required' => false,
                'placeholder' => 'formulario.ninguno',
            ])
            ->add('sede', EnumType::class, [
                'label' => 'usuario.sede',
                'class' => Sede::class,
                'choice_label' => fn (Sede $sede) => $sede->etiqueta(),
                'required' => false,
                'placeholder' => 'formulario.ninguna',
            ])
            ->add('ubicacion', TextType::class, ['label' => 'usuario.ubicacion', 'required' => false, 'help' => 'usuario.ubicacion_ayuda'])
            ->add('telefono', TelType::class, ['label' => 'usuario.telefono', 'required' => false])
            ->add('enlaceCita', UrlType::class, ['label' => 'usuario.enlace_cita', 'required' => false, 'default_protocol' => 'https', 'help' => 'usuario.enlace_cita_ayuda'])
            ->add('materias', EntityType::class, [
                'label' => 'usuario.materias',
                'class' => Materia::class,
                'choice_label' => 'nombre',
                'query_builder' => fn (MateriaRepository $r) => $r->createQueryBuilder('m')->where('m.activa = true')->orderBy('m.nombre'),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'usuario.materias_ayuda',
            ])
            ->add('biografia', TextareaType::class, ['label' => 'usuario.biografia', 'required' => false, 'attr' => ['rows' => 5]])
            ->add('biografiaEn', TextareaType::class, ['label' => 'usuario.biografia_en', 'required' => false, 'attr' => ['rows' => 5]])
            ->add('archivoFoto', FileType::class, [
                'label' => 'usuario.foto',
                'mapped' => false,
                'required' => false,
                'help' => 'usuario.foto_ayuda',
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [new Assert\Image(
                    maxSize: '2M',
                    mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    maxWidth: 4000,
                    maxHeight: 4000,
                )],
            ])
            ->add('visibleEnDirectorio', CheckboxType::class, ['label' => 'usuario.visible', 'required' => false]);

        if ($options['tiene_foto']) {
            $builder->add('eliminarFoto', CheckboxType::class, ['label' => 'usuario.eliminar_foto', 'mapped' => false, 'required' => false]);
        }

        if ($admin) {
            $builder
                ->add('ldapUsuario', TextType::class, [
                    'label' => 'usuario.ldap',
                    'required' => false,
                    'help' => 'usuario.ldap_ayuda',
                ])
                ->add('nuevaContrasena', PasswordType::class, [
                    'label' => 'usuario.contrasena',
                    'mapped' => false,
                    'required' => false,
                    'help' => 'usuario.contrasena_ayuda',
                    'attr' => ['autocomplete' => 'new-password'],
                    'constraints' => [new Assert\Length(min: 12, max: 4096)],
                ])
                ->add('activo', CheckboxType::class, ['label' => 'usuario.activo', 'required' => false]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Usuario::class,
            'modo' => 'perfil',
            'tiene_foto' => false,
        ]);
        $resolver->setAllowedValues('modo', ['admin', 'perfil']);
        $resolver->setAllowedTypes('tiene_foto', 'bool');
    }
}
