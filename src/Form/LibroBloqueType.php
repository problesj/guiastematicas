<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Materia;
use App\Repository\MateriaRepository;
use App\Servicio\DatosLibro;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use App\Servicio\MetadatosDoi;
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
 * Un libro del bloque "Libros". Con el ISBN o el DOI basta: título, autor, editorial y año se toman del catálogo de la
 * biblioteca (Primo VE) o de doi.org; lo escrito a mano tiene prioridad. La portada se obtiene de Open Library por ISBN.
 *
 * Al guardar, el libro se incorpora a la lista de recursos (ver LibrosEnRecursos): "recurso" guarda el id del recurso
 * vinculado y "materias" son las materias de ese recurso.
 */
final class LibroBloqueType extends AbstractType
{
    public function __construct(private readonly DatosLibro $datos)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('isbn', TextType::class, [
                'label' => 'ISBN',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'data-libro-datos-target' => 'isbn'],
                'constraints' => [new Assert\Isbn(message: 'bloque.isbn_no_valido')],
            ])
            ->add('doi', TextType::class, [
                'label' => 'DOI',
                'required' => false,
                'attr' => ['placeholder' => '10.1007/978-…', 'autocomplete' => 'off', 'data-libro-datos-target' => 'doi'],
                'constraints' => [
                    new Assert\Length(max: 200),
                    new Assert\Callback(function (?string $doi, ExecutionContextInterface $contexto): void {
                        if (null !== $doi && '' !== trim($doi) && null === MetadatosDoi::normalizar($doi)) {
                            $contexto->buildViolation('bloque.doi_no_valido')->addViolation();
                        }
                    }),
                ],
            ])
            ->add('titulo', TextType::class, [
                'label' => 'bloque.libro_titulo',
                'required' => false,
                'attr' => ['data-libro-datos-target' => 'titulo'],
                'constraints' => [new Assert\Length(max: 300)],
            ])
            ->add('anio', TextType::class, [
                'label' => 'bloque.libro_anio',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'maxlength' => 4, 'data-libro-datos-target' => 'anio'],
                'constraints' => [new Assert\Regex('/^\d{4}$/', message: 'bloque.anio_no_valido')],
            ])
            ->add('autor', TextType::class, [
                'label' => 'bloque.libro_autor',
                'required' => false,
                'attr' => ['data-libro-datos-target' => 'autor'],
                'constraints' => [new Assert\Length(max: 200)],
            ])
            ->add('editorial', TextType::class, [
                'label' => 'bloque.libro_editorial',
                'required' => false,
                'attr' => ['data-libro-datos-target' => 'editorial'],
                'constraints' => [new Assert\Length(max: 200)],
            ])
            ->add('enlace', UrlType::class, [
                'label' => 'bloque.libro_enlace',
                'required' => false,
                'default_protocol' => 'https',
                'help' => 'bloque.libro_enlace_ayuda',
                'constraints' => [new Assert\Url(requireTld: true), new Assert\Length(max: 500)],
            ])
            ->add('materias', EntityType::class, [
                'label' => 'bloque.libro_materias',
                'help' => 'bloque.libro_materias_ayuda',
                'class' => Materia::class,
                'choice_label' => 'nombre',
                'query_builder' => fn (MateriaRepository $r) => $r->createQueryBuilder('m')->where('m.activa = true')->orderBy('m.nombre'),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('recurso', HiddenType::class, ['required' => false]);

        // Completa los campos vacíos con el catálogo o doi.org al guardar (el botón «Buscar datos» lo hace antes).
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $evento): void {
            if (\is_array($evento->getData())) {
                $evento->setData($this->datos->completar($evento->getData()));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('constraints', [new Assert\Callback(function (mixed $libro, ExecutionContextInterface $contexto): void {
            if (!\is_array($libro) || '' !== trim((string) ($libro['titulo'] ?? ''))) {
                return;
            }
            $identificador = trim((string) (($libro['isbn'] ?? '') ?: ($libro['doi'] ?? '')));
            $contexto->buildViolation('' !== $identificador ? 'bloque.libro_no_encontrado' : 'bloque.libro_sin_titulo')
                ->setParameter('%isbn%', $identificador)
                ->atPath('[titulo]')
                ->addViolation();
        })]);
    }
}
