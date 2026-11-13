import { Controller } from '@hotwired/stimulus';

/*
 * Botón «Buscar datos» de un libro: con el ISBN o el DOI pide al servidor los datos del catálogo de la biblioteca
 * o de doi.org y completa título, autor, editorial y año (lo ya escrito se reemplaza; nada se guarda hasta «Guardar»).
 */
export default class extends Controller {
    static targets = ['isbn', 'doi', 'titulo', 'autor', 'editorial', 'anio', 'boton', 'estado'];
    static values = { url: String, buscando: String, falta: String, vacio: String, error: String, catalogo: String, doi: String };

    async buscar() {
        const isbn = this.isbnTarget.value.trim();
        const doi = this.doiTarget.value.trim();
        if (!isbn && !doi) {
            this.avisar(this.faltaValue, true);
            this.isbnTarget.focus();
            return;
        }

        this.botonTarget.disabled = true;
        this.avisar(this.buscandoValue);
        try {
            const respuesta = await fetch(`${this.urlValue}?${new URLSearchParams({ isbn, doi })}`, { headers: { Accept: 'application/json' } });
            if (404 === respuesta.status) {
                this.avisar(this.vacioValue, true);
                return;
            }
            if (!respuesta.ok) {
                throw new Error(String(respuesta.status));
            }
            const datos = await respuesta.json();
            for (const campo of ['titulo', 'autor', 'editorial', 'anio']) {
                if (datos[campo]) {
                    this[`${campo}Target`].value = datos[campo];
                }
            }
            if (datos.isbn && !isbn) {
                this.isbnTarget.value = datos.isbn;
            }
            this.dispatch('completado');
            this.avisar(datos.origen.map((origen) => ('catalogo' === origen ? this.catalogoValue : this.doiValue)).join(' '));
        } catch {
            this.avisar(this.errorValue, true);
        } finally {
            this.botonTarget.disabled = false;
        }
    }

    avisar(texto, problema = false) {
        this.estadoTarget.textContent = texto;
        this.estadoTarget.classList.toggle('libro-form__estado--problema', problema);
    }
}
