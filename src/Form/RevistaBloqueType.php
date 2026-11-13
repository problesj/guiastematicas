<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Materia;
use App\Repository\MateriaRepository;
use App\Servicio\DatosRevista;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Una revista del bloque "Revistas". Con el ISSN basta: título y editorial se toman de la búsqueda de revistas del
 * catálogo (Primo VE); lo escrito a mano tiene prioridad. Como los libros, al guardar se incorpora a la lista de
 * recursos (tipo Revistas): "recurso" guarda el id del recurso vinculado y "materias" son las materias de ese recurso.
 */
final class RevistaBloqueType extends AbstractType
{
    public function __construct(private readonly DatosRevista $datos)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('issn', TextType::class, [
                'label' => 'ISSN',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'placeholder' => '0000-0000', 'maxlength' => 9, 'data-revista-datos-target' => 'issn'],
                'constraints' => [new Assert\Issn(message: 'bloque.issn_no_valido')],
            ])
            ->add('eissn', TextType::class, [
                'label' => 'bloque.revista_eissn',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'placeholder' => '0000-0000', 'maxlength' => 9, 'data-revista-datos-target' => 'eissn'],
                'constraints' => [new Assert\Issn(message: 'bloque.issn_no_valido')],
            ])
            ->add('titulo', TextType::class, [
                'label' => 'bloque.libro_titulo',
                'required' => false,
                'attr' => ['data-revista-datos-target' => 'titulo'],
                'constraints' => [new Assert\Length(max: 300)],
            ])
            ->add('editorial', TextType::class, [
                'label' => 'bloque.libro_editorial',
                'required' => false,
                'attr' => ['data-revista-datos-target' => 'editorial'],
                'constraints' => [new Assert\Length(max: 200)],
            ])
            ->add('enlace', UrlType::class, [
                'label' => 'bloque.libro_enlace',
                'required' => false,
                'default_protocol' => 'https',
                'help' => 'bloque.revista_enlace_ayuda',
                'constraints' => [new Assert\Url(requireTld: true), new Assert\Length(max: 500)],
            ])
            ->add('materias', EntityType::class, [
                'label' => 'bloque.libro_materias',
                'help' => 'bloque.revista_materias_ayuda',
                'class' => Materia::class,
                'choice_label' => 'nombre',
                'query_builder' => fn (MateriaRepository $r) => $r->createQueryBuilder('m')->where('m.activa = true')->orderBy('m.nombre'),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('recurso', HiddenType::class, ['required' => false]);

        // Completa los campos vacíos con el catálogo al guardar (el botón «Buscar datos» lo hace antes).
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $evento): void {
            if (\is_array($evento->getData())) {
                $evento->setData($this->datos->completar($evento->getData()));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('constraints', [new Assert\Callback(function (mixed $revista, ExecutionContextInterface $contexto): void {
            if (!\is_array($revista) || '' !== trim((string) ($revista['titulo'] ?? ''))) {
                return;
            }
            $issn = trim((string) ($revista['issn'] ?? ''));
            $contexto->buildViolation('' !== $issn ? 'bloque.revista_no_encontrada' : 'bloque.revista_sin_titulo')
                ->setParameter('%issn%', $issn)
                ->atPath('[titulo]')
                ->addViolation();
        })]);
    }
}
