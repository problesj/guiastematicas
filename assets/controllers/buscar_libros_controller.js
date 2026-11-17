import { Controller } from '@hotwired/stimulus';

/*
 * Buscador de un bloque Libros o Revistas largo, en la guía: filtra mientras se escribe por título, autor, editorial,
 * año o ISBN/ISSN (sin distinguir mayúsculas ni tildes). Marca los elementos que no coinciden con data-descartado y
 * avisa con el evento «buscar-libros:filtrado» para que la paginación (paginar-lista) se rehaga; sin paginación, los
 * oculta él mismo. Sin JavaScript el buscador queda oculto y se ven todos.
 */
export default class extends Controller {
    static targets = ['buscador', 'campo', 'lista', 'estado', 'vacio'];
    static values = { resultados: String };

    connect() {
        this.buscadorTarget.hidden = false;
    }

    static normalizar(texto) {
        return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[-\s]+/g, ' ').trim();
    }

    filtrar() {
        const consulta = this.constructor.normalizar(this.campoTarget.value);
        const palabras = consulta === '' ? [] : consulta.split(' ');
        const items = [...this.listaTarget.children];
        let visibles = 0;
        items.forEach((item) => {
            const texto = item.dataset.busquedaNormalizada ??= this.constructor.normalizar(item.dataset.busqueda || item.textContent);
            const coincide = palabras.every((p) => texto.includes(p) || texto.replace(/ /g, '').includes(p));
            item.toggleAttribute('data-descartado', !coincide);
            item.hidden = !coincide;
            visibles += coincide ? 1 : 0;
        });
        this.vacioTarget.hidden = visibles > 0;
        this.estadoTarget.textContent = palabras.length === 0 ? '' : this.resultadosValue.replace('%visibles%', String(visibles)).replace('%total%', String(items.length));
        this.dispatch('filtrado', { target: this.listaTarget }); // sube hasta la lista paginada
        this.listaTarget.dispatchEvent(new Event('scroll')); // carrusel: actualiza sus botones
    }

    limpiar(evento) {
        if (evento.key === 'Escape' && this.campoTarget.value !== '') {
            this.campoTarget.value = '';
            this.filtrar();
        }
    }
}
