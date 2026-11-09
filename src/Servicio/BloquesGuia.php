<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Entity\Bloque;
use App\Entity\Recurso;
use App\Entity\Usuario;
use App\Repository\RecursoRepository;
use App\Repository\UsuarioRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;

/** Datos que necesitan los bloques de una guía al mostrarse (recursos, perfil, búsqueda en Primo VE). */
final class BloquesGuia
{
    public function __construct(
        private readonly RecursoRepository $recursos,
        private readonly UsuarioRepository $usuarios,
        #[Autowire('%env(string:default::PRIMO_URL)%')]
        private readonly string $primoUrl,
        #[Autowire('%env(string:default::PRIMO_VID)%')]
        private readonly string $primoVid,
    ) {
    }

    /**
     * Recursos del bloque en el orden elegido. En la vista pública se omiten los ocultos o con la prueba vencida.
     *
     * @return list<Recurso>
     */
    #[AsTwigFunction('recursos_del_bloque')]
    public function recursos(Bloque $bloque, bool $soloDisponibles = true): array
    {
        $ids = array_values(array_filter((array) $bloque->dato('ids', []), 'is_int'));
        if ([] === $ids) {
            return [];
        }
        $porId = [];
        foreach ($this->recursos->findBy(['id' => $ids]) as $recurso) {
            $porId[$recurso->getId()] = $recurso;
        }
        $lista = [];
        foreach ($ids as $id) {
            $recurso = $porId[$id] ?? null;
            if (null !== $recurso && (!$soloDisponibles || $recurso->estaDisponible())) {
                $lista[] = $recurso;
            }
        }

        return $lista;
    }

    #[AsTwigFunction('perfil_del_bloque')]
    public function perfil(Bloque $bloque): ?Usuario
    {
        $id = $bloque->dato('usuario');
        $usuario = \is_int($id) ? $this->usuarios->find($id) : null;

        return null !== $usuario && $usuario->isActivo() ? $usuario : null;
    }

    /** Si el bloque de búsqueda puede funcionar (PRIMO_URL y PRIMO_VID definidos). */
    #[AsTwigFunction('hay_buscador_primo')]
    public function hayBuscadorPrimo(): bool
    {
        return '' !== $this->primoUrl && '' !== $this->primoVid;
    }

    /** Dirección de resultados de Primo VE para un texto, p. ej. .../discovery/search?query=any,contains,agua&vid=... */
    public function enlacePrimo(string $texto, string $idioma): ?string
    {
        if (!$this->hayBuscadorPrimo()) {
            return null;
        }

        // RFC 3986 (espacios como %20) y comas y dos puntos legibles, como en las direcciones del propio Primo.
        return rtrim($this->primoUrl, '/').'/discovery/search?'.strtr(http_build_query([
            'query' => 'any,contains,'.str_replace(',', ' ', trim($texto)),
            'tab' => 'Everything',
            'search_scope' => 'MyInst_and_CI',
            'vid' => $this->primoVid,
            'lang' => $idioma,
            'offset' => 0,
        ], '', '&', \PHP_QUERY_RFC3986), ['%2C' => ',', '%3A' => ':']);
    }
}
