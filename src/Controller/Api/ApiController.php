<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Pestana;
use App\Entity\Recurso;
use App\Enum\TipoAcceso;
use App\Enum\TipoBloque;
use App\Enum\TipoRecurso;
use App\Paginacion\Pagina;
use App\Repository\GuiaRepository;
use App\Repository\MateriaRepository;
use App\Repository\RecursoRepository;
use App\Servicio\AlmacenImagenesGuia;
use App\Servicio\BloquesGuia;
use App\Servicio\Catalogo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * API pública de solo lectura (JSON) para mostrar guías y recursos en otros sitios. Solo entrega lo que ya es público:
 * guías publicadas y no restringidas, y recursos de la lista A-Z. Se puede llamar desde cualquier origen (CORS).
 */
#[Route('/api/v1', name: 'api_', format: 'json')]
final class ApiController extends AbstractController
{
    public function __construct(
        private readonly BloquesGuia $bloques,
        private readonly Catalogo $catalogo,
    ) {
    }

    #[Route('', name: 'indice', methods: ['GET'])]
    public function indice(): JsonResponse
    {
        return $this->json([
            'guias' => $this->generateUrl('api_guias', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'guia' => $this->generateUrl('api_guia', ['slug' => 'SLUG'], UrlGeneratorInterface::ABSOLUTE_URL),
            'recursos' => $this->generateUrl('api_recursos', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'incrustar' => $this->generateUrl('raiz', [], UrlGeneratorInterface::ABSOLUTE_URL).'incrustar.js',
        ]);
    }

    /** Guías publicadas; filtros opcionales: materia (slug), q (texto), idioma (es|en). */
    #[Route('/guias', name: 'guias', methods: ['GET'])]
    public function guias(Request $request, GuiaRepository $guias, MateriaRepository $materias): JsonResponse
    {
        $slugMateria = $request->query->getString('materia');
        $materia = '' !== $slugMateria ? $materias->findOneBy(['slug' => $slugMateria, 'activa' => true]) : null;
        if ('' !== $slugMateria && null === $materia) {
            return $this->publico(['guias' => []]);
        }
        $idioma = $request->query->getString('idioma');
        $lista = $guias->publicadas($materia, mb_substr(trim($request->query->getString('q')), 0, 100));
        if (\in_array($idioma, ['es', 'en'], true)) {
            $lista = array_filter($lista, fn (Guia $g) => $g->getIdioma() === $idioma);
        }

        return $this->publico(['guias' => array_values(array_map($this->resumenGuia(...), $lista))]);
    }

    /** Una guía con sus pestañas, secciones y bloques. */
    #[Route('/guias/{slug<[a-z0-9-]+>}', name: 'guia', methods: ['GET'])]
    public function guia(string $slug, GuiaRepository $guias): JsonResponse
    {
        $guia = $guias->publicadaPorSlug($slug);
        if (null === $guia || $guia->isRestringida()) {
            return $this->publico(['error' => 'No existe una guía pública con esa dirección.'], 404);
        }

        return $this->publico($this->resumenGuia($guia) + [
            'pestanas' => array_map(fn (Pestana $p) => [
                'titulo' => $p->getTitulo(),
                'slug' => $p->getSlug(),
                'url' => $p->getEnlace() ?? $this->generateUrl('guia', [
                    '_locale' => $guia->getIdioma(),
                    'slug' => $guia->getSlug(),
                    'pestana' => $p === ($guia->getPaginas()[0] ?? null) ? null : $p->getSlug(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'padre' => $p->getPadre()?->getSlug(),
                'secciones' => $p->getEnlace() ? [] : array_values(array_map(fn ($s) => [
                    'titulo' => $s->getTitulo(),
                    'bloques' => array_values(array_map(fn (Bloque $b) => $this->bloque($b, $guia), $s->getBloques()->toArray())),
                ], $p->getSecciones()->toArray())),
            ], array_values($guia->getPestanas()->toArray())),
        ]);
    }

    /**
     * Recursos de la lista A-Z, por páginas. Filtros: q, letra, materia (slug), tipo, acceso, idioma (de las
     * descripciones), pagina y por_pagina (10, 25, 50 o 100).
     */
    #[Route('/recursos', name: 'recursos', methods: ['GET'])]
    public function recursos(Request $request, RecursoRepository $recursos, MateriaRepository $materias): JsonResponse
    {
        $consulta = $request->query;
        $letra = strtoupper($consulta->getString('letra'));
        $slugMateria = $consulta->getString('materia');
        $materia = '' !== $slugMateria ? $materias->findOneBy(['slug' => $slugMateria, 'activa' => true]) : null;
        if ('' !== $slugMateria && null === $materia) {
            return $this->publico(['total' => 0, 'pagina' => 1, 'paginas' => 1, 'recursos' => []]);
        }
        $idioma = 'en' === $consulta->getString('idioma') ? 'en' : 'es';
        [$numero, $porPagina] = Pagina::leerParametros($request);
        $pagina = $recursos->buscarPublicos(
            $numero,
            $porPagina,
            1 === preg_match('/^[A-Z#]$/', $letra) ? $letra : null,
            mb_substr(trim($consulta->getString('q')), 0, 100),
            $materia,
            TipoRecurso::tryFrom($consulta->getString('tipo')),
            TipoAcceso::tryFrom($consulta->getString('acceso')),
        );

        return $this->publico([
            'total' => $pagina->total,
            'pagina' => $pagina->numero,
            'paginas' => $pagina->getPaginas(),
            'recursos' => array_map(fn (Recurso $r) => $this->recurso($r, $idioma), $pagina->elementos),
        ]);
    }

    /** @return array<string, mixed> */
    private function resumenGuia(Guia $guia): array
    {
        $idioma = $guia->getIdioma();
        $responsable = $guia->getResponsable();
        $perfilVisible = null !== $responsable && $responsable->isActivo() && $responsable->isVisibleEnDirectorio() && $responsable->getRol()->esPersonal();

        return [
            'titulo' => $guia->getTitulo(),
            'slug' => $guia->getSlug(),
            'idioma' => $idioma,
            'descripcion' => $guia->getDescripcion(),
            'url' => $this->generateUrl('guia', ['_locale' => $idioma, 'slug' => $guia->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            'api' => $this->generateUrl('api_guia', ['slug' => $guia->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            'actualizada' => $guia->getActualizadaEn()->format(\DATE_ATOM),
            'materias' => array_values(array_map(
                fn (Materia $m) => ['slug' => $m->getSlug(), 'nombre' => $m->getNombreTraducido($idioma)],
                array_filter($guia->getMaterias()->toArray(), fn (Materia $m) => $m->isActiva()),
            )),
            'responsable' => null === $responsable ? null : [
                'nombre' => $responsable->getNombreCompleto(),
                'perfil' => $perfilVisible ? $this->generateUrl('personal_perfil', ['_locale' => $idioma, 'slug' => $responsable->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bloque(Bloque $bloque, Guia $guia): array
    {
        $idioma = $guia->getIdioma();
        $items = array_values(array_filter((array) $bloque->dato('items', []), 'is_array'));
        $contenido = match ($bloque->getTipo()) {
            TipoBloque::Texto => ['html' => $bloque->dato('html')],
            TipoBloque::Recursos => ['recursos' => array_map(fn (Recurso $r) => $this->recurso($r, $idioma), $this->bloques->recursos($bloque))],
            TipoBloque::Perfil => ['persona' => $this->bloques->perfil($bloque)?->getNombreCompleto()],
            TipoBloque::Video => ['url' => $bloque->dato('url'), 'descripcion' => $bloque->dato('descripcion')],
            TipoBloque::Busqueda => [
                'texto' => $bloque->dato('texto'),
                'tipo' => $bloque->dato('tipo', 'todos'),
                'materias' => array_map(fn (Materia $m) => $m->getSlug(), $this->bloques->materiasBusqueda($bloque)),
                'buscar' => $this->generateUrl('catalogo', ['_locale' => $idioma], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
            TipoBloque::Enlaces => ['enlaces' => $items],
            TipoBloque::Libros => ['libros' => array_map(fn (array $l) => [
                'titulo' => $l['titulo'] ?? null,
                'autor' => $l['autor'] ?? null,
                'editorial' => $l['editorial'] ?? null,
                'anio' => $l['anio'] ?? null,
                'isbn' => $l['isbn'] ?? null,
                'doi' => $l['doi'] ?? null,
                'url' => $l['enlace'] ?? (isset($l['registro']) ? $this->catalogo->enlaceRegistro($l['registro'], $idioma) : null) ?? (isset($l['doi']) ? 'https://doi.org/'.$l['doi'] : null),
                'portada' => isset($l['isbn']) ? 'https://covers.openlibrary.org/b/isbn/'.rawurlencode($l['isbn']).'-M.jpg?default=false' : null,
            ], $items)],
            TipoBloque::Revistas => ['revistas' => array_map(fn (array $r) => [
                'titulo' => $r['titulo'] ?? null,
                'editorial' => $r['editorial'] ?? null,
                'issn' => $r['issn'] ?? null,
                'eissn' => $r['eissn'] ?? null,
                'url' => $r['enlace'] ?? (isset($r['registro']) ? $this->catalogo->enlaceRegistro($r['registro'], $idioma) : null),
            ], $items)],
            TipoBloque::Diapositivas => ['diapositivas' => array_map(fn (array $d) => [
                'titulo' => $d['titulo'] ?? null,
                'enlace' => $d['enlace'] ?? null,
                'imagen' => $this->generateUrl('raiz', [], UrlGeneratorInterface::ABSOLUTE_URL).AlmacenImagenesGuia::RUTA_PUBLICA.'/'.rawurlencode((string) ($d['imagen'] ?? '')),
            ], $items)],
        };

        return [
            'id' => $bloque->getId(),
            'tipo' => $bloque->getTipo()->value,
            'titulo' => $bloque->getTitulo(),
            'incrustar' => $this->generateUrl('incrustar_bloque', ['_locale' => $idioma, 'id' => $bloque->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ] + $contenido;
    }

    /** @return array<string, mixed> */
    private function recurso(Recurso $recurso, string $idioma): array
    {
        return [
            'id' => $recurso->getId(),
            'titulo' => $recurso->getTitulo(),
            'tipo' => $recurso->getTipo()->value,
            'acceso' => $recurso->getAcceso()->value,
            'descripcion' => $recurso->getDescripcionTraducida($idioma),
            'proveedor' => $recurso->getProveedor(),
            // Pasa por el proxy AccesUM cuando el recurso lo requiere.
            'url' => $this->generateUrl('recursos_ir', ['id' => $recurso->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            'materias' => array_values(array_map(fn (Materia $m) => $m->getSlug(), $recurso->getMaterias()->toArray())),
        ];
    }

    /** @param array<string, mixed> $datos */
    private function publico(array $datos, int $estado = 200): JsonResponse
    {
        $respuesta = new JsonResponse($datos, $estado);
        $respuesta->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $respuesta->headers->set('Access-Control-Allow-Origin', '*');
        $respuesta->headers->set('X-Robots-Tag', 'noindex');
        $respuesta->setPublic()->setMaxAge(300);

        return $respuesta;
    }
}
