import { Controller } from '@hotwired/stimulus';

/*
 * Libros del bloque «Libros» (y revistas del bloque «Revistas») en el editor: cada libro se muestra como una fila (portada, título, autor, año, ISBN) con
 * Ver, Editar y Borrar, y la lista se pagina de 5, 10, 15 o 20. El formulario de cada libro sigue en la página (oculto
 * hasta pulsar «Editar»), así que todo se guarda junto con «Guardar», como antes. Sin JavaScript se ve todo abierto.
 */
export default class extends Controller {
    static targets = ['lista', 'item', 'controles', 'tamano', 'estado', 'paginas', 'anterior', 'siguiente', 'numero'];
    static values = { nuevo: String, rango: String, pagina: String, vacio: String, catalogo: String };

    connect() {
        this.actual = 0;
        this.controlesTarget.hidden = false;
        // Todos cerrados salvo los que volvieron con errores; se muestra la página del primero de ellos.
        this.itemTargets.forEach((item) => this.abrir(item, item.hasAttribute('data-con-errores')));
        const conErrores = this.itemTargets.findIndex((item) => item.hasAttribute('data-con-errores'));
        if (conErrores >= 0) {
            this.actual = Math.floor(conErrores / this.tamano());
        }
        this.refrescar();
        // Libros agregados, quitados o movidos (también los del buscador): se muestra la página del último agregado.
        this.observador = new MutationObserver((cambios) => {
            const agregados = cambios.flatMap((c) => [...c.addedNodes]).filter((n) => n.nodeType === Node.ELEMENT_NODE);
            if (this.reordenando) {
                this.reordenando = false;
                this.actual = 0;
            } else if (agregados.length > 0) {
                this.actual = Math.floor(this.items().indexOf(agregados.at(-1)) / this.tamano());
            }
            // Espera a que el buscador termine de completar los campos del libro recién agregado.
            setTimeout(() => this.refrescar());
        });
        this.observador.observe(this.listaTarget, { childList: true });

        // Orden del bloque: «Por título» ordena la lista enseguida (el servidor la vuelve a ordenar al guardar) y
        // oculta las flechas del orden manual.
        this.formulario = this.element.closest('form');
        this.cambioOrden = (evento) => {
            if (evento.target.name?.endsWith('[orden]')) {
                this.aplicarOrden(true);
            }
        };
        this.formulario?.addEventListener('change', this.cambioOrden);
        this.aplicarOrden(false);
    }

    porTitulo() {
        return this.formulario?.querySelector('input[name$="[orden]"]:checked')?.value === 'titulo';
    }

    aplicarOrden(ordenar) {
        const titulo = this.porTitulo();
        this.element.classList.toggle('coleccion--por-titulo', titulo);
        if (titulo && ordenar) {
            const clave = (item) => item.querySelector('input[name$="[titulo]"]')?.value.trim() ?? '';
            const ordenados = this.items().sort((a, b) => clave(a).localeCompare(clave(b), 'es', { sensitivity: 'base' }));
            this.reordenando = true;
            this.listaTarget.append(...ordenados);
        }
    }

    disconnect() {
        this.observador?.disconnect();
        this.formulario?.removeEventListener('change', this.cambioOrden);
    }

    items() {
        return [...this.listaTarget.children];
    }

    tamano() {
        return Number(this.tamanoTarget.value) || 10;
    }

    /** «Agregar libro» o «Agregar revista»: el nuevo queda abierto y con el cursor en el ISBN o el ISSN. */
    nuevo() {
        const item = this.items().at(-1);
        if (item) {
            this.abrir(item, true);
            item.querySelector('input[name$="[isbn]"], input[name$="[issn]"]')?.focus();
        }
    }

    editar(evento) {
        const item = evento.currentTarget.closest('li');
        const abrir = evento.currentTarget.getAttribute('aria-expanded') !== 'true';
        this.abrir(item, abrir);
        if (abrir) {
            item.querySelector('[data-fila="edicion"] input:not([type="hidden"])')?.focus();
        }
    }

    abrir(item, abierto) {
        const edicion = item.querySelector('[data-fila="edicion"]');
        const boton = item.querySelector('[data-action~="paginar-libros#editar"]');
        if (edicion) {
            edicion.hidden = !abierto;
        }
        boton?.setAttribute('aria-expanded', String(abierto));
        item.classList.toggle('coleccion__item--abierto', abierto);
    }

    cambiarTamano() {
        this.actual = 0;
        this.mostrar();
    }

    anterior() {
        this.actual--;
        this.mostrar();
        this.anteriorTarget.disabled ? this.siguienteTarget.focus() : this.anteriorTarget.focus();
    }

    siguiente() {
        this.actual++;
        this.mostrar();
        this.siguienteTarget.disabled ? this.anteriorTarget.focus() : this.siguienteTarget.focus();
    }

    /** Actualiza las filas con lo escrito en cada formulario y vuelve a paginar. */
    refrescar() {
        this.items().forEach((item) => this.fila(item));
        this.mostrar();
    }

    mostrar() {
        const items = this.items();
        const tamano = this.tamano();
        const paginas = Math.max(1, Math.ceil(items.length / tamano));
        this.actual = Math.min(Math.max(0, this.actual), paginas - 1);
        const desde = this.actual * tamano;
        items.forEach((item, i) => { item.hidden = i < desde || i >= desde + tamano; });

        this.estadoTarget.textContent = items.length === 0 ? this.vacioValue : this.rangoValue
            .replace('%desde%', String(desde + 1))
            .replace('%hasta%', String(Math.min(desde + tamano, items.length)))
            .replace('%total%', String(items.length));
        this.paginasTarget.hidden = paginas < 2;
        this.numeroTarget.textContent = this.paginaValue.replace('%pagina%', String(this.actual + 1)).replace('%paginas%', String(paginas));
        this.anteriorTarget.disabled = this.actual === 0;
        this.siguienteTarget.disabled = this.actual >= paginas - 1;
    }

    fila(item) {
        const valor = (nombre) => item.querySelector(`[name$="[${nombre}]"]`)?.value.trim() ?? '';
        const [titulo, autor, anio, isbn, doi, enlace, issn, editorial] = ['titulo', 'autor', 'anio', 'isbn', 'doi', 'enlace', 'issn', 'editorial'].map(valor);
        const revista = item.classList.contains('coleccion__item--revista');
        const nombre = titulo || this.nuevoValue;
        const poner = (clave, texto) => item.querySelectorAll(`[data-fila="${clave}"]`).forEach((e) => { e.textContent = texto; });
        poner('titulo', nombre);
        poner('nombre', nombre);
        poner('detalle', (revista ? [editorial, issn ? `ISSN ${issn}` : ''] : [autor, anio, isbn ? `ISBN ${isbn}` : '']).filter(Boolean).join(' · '));

        // «Ver»: lo que abre el libro en la guía (enlace propio, registro del catálogo, DOI o búsqueda en el catálogo).
        const busqueda = isbn || issn || titulo;
        const destino = enlace || item.dataset.registro || (doi ? `https://doi.org/${doi}` : '')
            || (this.catalogoValue && busqueda ? `${this.catalogoValue}?${new URLSearchParams({ q: busqueda })}` : '');
        const ver = item.querySelector('[data-fila="ver"]');
        if (ver) {
            ver.hidden = !destino;
            ver.href = destino || '#';
        }

        const portada = item.querySelector('.libro-fila__portada img');
        const limpio = isbn.replace(/-/g, '');
        if (portada && portada.dataset.isbn !== limpio) {
            portada.dataset.isbn = limpio;
            portada.hidden = !limpio;
            if (limpio) {
                portada.src = `https://covers.openlibrary.org/b/isbn/${encodeURIComponent(limpio)}-S.jpg?default=false`;
            }
        }
    }
}
