<?php

declare(strict_types=1);

namespace App\Servicio;

use App\Entity\Autor;
use App\Entity\Editorial;
use App\Repository\AutorRepository;
use App\Repository\EditorialRepository;

/**
 * Encuentra o crea autores y editoriales a partir de texto (formulario de recursos e importación).
 *
 * Lo creado no se guarda aquí: se guarda junto con el recurso que lo usa (cascade persist). Primero se busca en la
 * base; si no está, se reutiliza lo creado antes y aún no guardado, para que dos filas de una importación con el mismo
 * autor no lo dupliquen.
 */
final class RegistroBibliografico
{
    /** @var array<string, Autor> autores nuevos aún sin guardar */
    private array $autores = [];

    /** @var array<string, Editorial> editoriales nuevas aún sin guardar */
    private array $editoriales = [];

    public function __construct(
        private readonly AutorRepository $repositorioAutores,
        private readonly EditorialRepository $repositorioEditoriales,
    ) {
    }

    /**
     * "Pérez, Ana; Soto, Luis" => [Autor, Autor].
     *
     * @return list<Autor>
     */
    public function autoresDesdeTexto(?string $texto): array
    {
        $autores = [];
        foreach (preg_split('/\s*;\s*/u', (string) $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $nombre) {
            $autor = $this->autor(...Autor::separarNombre($nombre));
            if (null !== $autor) {
                $autores[] = $autor;
            }
        }

        return $autores;
    }

    /** Busca el autor por nombre (sin distinguir mayúsculas) o crea uno nuevo. Null si no hay apellidos. */
    public function autor(string $apellidos, string $nombres = ''): ?Autor
    {
        $autor = (new Autor())->setApellidos(mb_substr($apellidos, 0, 150))->setNombres(mb_substr($nombres, 0, 120));
        if ('' === $autor->getApellidos()) {
            if ('' === $autor->getNombres()) {
                return null;
            }
            // "Ana" sola: se guarda como apellido para que sea un autor válido.
            $autor->setApellidos($autor->getNombres())->setNombres('');
        }
        $clave = Autor::clave($autor->getApellidos(), $autor->getNombres());

        return $this->repositorioAutores->porNombre($autor->getApellidos(), $autor->getNombres())
            ?? $this->pendiente($this->autores, $clave)
            ?? $this->autores[$clave] = $autor;
    }

    public function editorial(?string $nombre): ?Editorial
    {
        $editorial = (new Editorial())->setNombre(mb_substr((string) $nombre, 0, 150));
        if ('' === $editorial->getNombre()) {
            return null;
        }
        $clave = Autor::clave($editorial->getNombre(), '');

        return $this->repositorioEditoriales->porNombre($editorial->getNombre())
            ?? $this->pendiente($this->editoriales, $clave)
            ?? $this->editoriales[$clave] = $editorial;
    }

    /**
     * Lo creado antes que todavía no tiene id (no se guardó). Si ya se guardó, la búsqueda en la base lo habría
     * encontrado, salvo que se haya borrado: en ambos casos se olvida.
     *
     * @template T of Autor|Editorial
     *
     * @param array<string, T> $pendientes
     *
     * @return T|null
     */
    private function pendiente(array &$pendientes, string $clave): Autor|Editorial|null
    {
        if (isset($pendientes[$clave]) && null !== $pendientes[$clave]->getId()) {
            unset($pendientes[$clave]);
        }

        return $pendientes[$clave] ?? null;
    }
}
