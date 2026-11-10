<?php

declare(strict_types=1);

namespace App\Form;

use App\Servicio\Catalogo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un libro del bloque "Libros". Con el ISBN basta: título, autor, editorial, año y registro se toman del catálogo
 * (Primo VE); lo escrito a mano tiene prioridad. La portada se obtiene de Open Library por ISBN.
 */
final class LibroBloqueType extends AbstractType
{
    public function __construct(private readonly Catalogo $catalogo)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('isbn', TextType::class, [
                'label' => 'ISBN',
                'required' => false,
                'help' => $this->catalogo->buscaPorIsbn() ? 'bloque.libro_isbn_ayuda' : null,
                'constraints' => [new Assert\Isbn(message: 'bloque.isbn_no_valido')],
            ])
            ->add('titulo', TextType::class, [
                'label' => $this->catalogo->buscaPorIsbn() ? 'bloque.libro_titulo_opcional' : 'bloque.libro_titulo',
                'required' => false,
                'constraints' => [new Assert\Length(max: 300)],
            ])
            ->add('autor', TextType::class, [
                'label' => 'bloque.libro_autor',
                'required' => false,
                'constraints' => [new Assert\Length(max: 200)],
            ])
            ->add('enlace', UrlType::class, [
                'label' => 'bloque.libro_enlace',
                'required' => false,
                'default_protocol' => 'https',
                'constraints' => [new Assert\Url(requireTld: true), new Assert\Length(max: 500)],
            ]);

        // Completa el libro con el catálogo. Los datos del catálogo se recalculan siempre desde el ISBN, para que
        // reordenar o cambiar un ISBN no deje datos de otro libro.
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $evento): void {
            $libro = $evento->getData();
            if (!\is_array($libro)) {
                return;
            }
            unset($libro['editorial'], $libro['anio'], $libro['registro'], $libro['mms']);
            $datos = $this->catalogo->libroPorIsbn((string) ($libro['isbn'] ?? ''));
            if (null !== $datos) {
                $libro['titulo'] = ($libro['titulo'] ?? null) ?: $datos['titulo'];
                $libro['autor'] = ($libro['autor'] ?? null) ?: $datos['autor'];
                $libro += ['editorial' => $datos['editorial'], 'anio' => $datos['anio'], 'registro' => $datos['registro'], 'mms' => $datos['mms']];
            }
            $evento->setData($libro);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('constraints', [new Assert\Callback(function (mixed $libro, ExecutionContextInterface $contexto): void {
            if (!\is_array($libro) || '' !== trim((string) ($libro['titulo'] ?? ''))) {
                return;
            }
            $isbn = trim((string) ($libro['isbn'] ?? ''));
            $contexto->buildViolation('' !== $isbn && $this->catalogo->buscaPorIsbn() ? 'bloque.libro_no_encontrado' : 'bloque.libro_sin_titulo')
                ->setParameter('%isbn%', $isbn)
                ->atPath('[titulo]')
                ->addViolation();
        })]);
    }
}
