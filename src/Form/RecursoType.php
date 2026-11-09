<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Autor;
use App\Entity\Materia;
use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoRecurso;
use App\Repository\AutorRepository;
use App\Repository\MateriaRepository;
use App\Servicio\RegistroBibliografico;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
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
    public function __construct(
        private readonly AutorRepository $autores,
        private readonly RegistroBibliografico $registro,
    ) {
    }

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
            // Lista JSON que arma el selector de autores: [{"id": 4}, {"apellidos": "Pérez", "nombres": "Ana"}, ...].
            ->add('autores', HiddenType::class, ['mapped' => false, 'required' => false])
            ->add('fuente', TextType::class, ['label' => 'recurso.fuente', 'required' => false, 'help' => 'recurso.fuente_ayuda'])
            ->add('editorial', TextType::class, [
                'label' => 'recurso.editorial',
                'mapped' => false,
                'required' => false,
                'help' => 'recurso.editorial_ayuda',
                'attr' => ['list' => 'editoriales-existentes', 'maxlength' => 150, 'autocomplete' => 'off'],
            ])
            ->add('anio', IntegerType::class, ['label' => 'recurso.anio', 'required' => false, 'attr' => ['min' => 1450, 'max' => 2100]])
            ->add('volumen', TextType::class, ['label' => 'recurso.volumen', 'required' => false])
            ->add('fasciculo', TextType::class, ['label' => 'recurso.fasciculo', 'required' => false])
            ->add('paginas', TextType::class, ['label' => 'recurso.paginas', 'required' => false, 'attr' => ['placeholder' => '15-32']])
            ->add('doi', TextType::class, ['label' => 'recurso.doi', 'required' => false, 'help' => 'recurso.doi_ayuda', 'attr' => ['placeholder' => '10.1016/j.cell.2020.01.001']])
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
            ->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $evento): void {
                $recurso = $evento->getData();
                if (!$recurso instanceof Recurso) {
                    return;
                }
                $evento->getForm()->get('autores')->setData(json_encode(array_map(
                    fn (Autor $a) => ['id' => $a->getId(), 'nombre' => $a->getNombreCompleto()],
                    array_filter($recurso->getAutores(), fn (Autor $a) => null !== $a->getId()),
                ), \JSON_UNESCAPED_UNICODE));
                $evento->getForm()->get('editorial')->setData($recurso->getEditorial()?->getNombre());
            })
            ->addEventListener(FormEvents::SUBMIT, function (FormEvent $evento): void {
                $recurso = $evento->getData();
                if (!$recurso instanceof Recurso) {
                    return;
                }
                $formulario = $evento->getForm();
                $recurso->setAutores($this->autoresElegidos((string) $formulario->get('autores')->getData()));
                $recurso->setEditorial($this->registro->editorial($formulario->get('editorial')->getData()));
                $recurso->limpiarCamposNoAplicables()->completarUrlDesdeDoi();
            });
    }

    /** @return list<Autor> */
    private function autoresElegidos(string $json): array
    {
        $elegidos = json_decode($json, true);
        if (!is_array($elegidos)) {
            return [];
        }
        $autores = [];
        foreach (array_slice($elegidos, 0, 100) as $elegido) {
            if (!is_array($elegido)) {
                continue;
            }
            $autor = isset($elegido['id']) && is_int($elegido['id'])
                ? $this->autores->find($elegido['id'])
                : $this->registro->autor((string) ($elegido['apellidos'] ?? ''), (string) ($elegido['nombres'] ?? ''));
            if (null !== $autor) {
                $autores[] = $autor;
            }
        }

        return $autores;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Recurso::class]);
    }
}
