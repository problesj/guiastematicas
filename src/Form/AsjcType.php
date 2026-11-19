<?php

declare(strict_types=1);

namespace App\Form;

use App\Clasificacion\Asjc;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Selector de categorías ASJC (Scopus): casillas agrupadas por campo en secciones desplegables, con un buscador
 * (controlador filtro-asjc). El valor es la lista de códigos.
 */
final class AsjcType extends AbstractType
{
    public function __construct(private readonly RequestStack $peticiones)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $idioma = $this->peticiones->getCurrentRequest()?->getLocale() ?? 'es';
        $opciones = [];
        $atributos = [];
        foreach (Asjc::porCampo($idioma) as $campo => $datos) {
            foreach ($datos['categorias'] as $codigo => $nombre) {
                $opciones[$codigo.' · '.$nombre] = $codigo;
                $atributos[$codigo] = ['data-campo' => $campo];
            }
        }
        $resolver->setDefaults([
            'choices' => $opciones,
            'choice_attr' => fn ($codigo) => $atributos[$codigo] ?? [],
            'choice_translation_domain' => false,
            'expanded' => true,
            'multiple' => true,
            'required' => false,
        ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $idioma = $this->peticiones->getCurrentRequest()?->getLocale() ?? 'es';
        $view->vars['campos'] = array_map(fn (array $d) => $d['nombre'], Asjc::porCampo($idioma));
        $marcados = [];
        foreach ((array) $form->getData() as $codigo) {
            $marcados[Asjc::campo((int) $codigo)] = ($marcados[Asjc::campo((int) $codigo)] ?? 0) + 1;
        }
        $view->vars['marcados'] = $marcados;
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'asjc';
    }
}
