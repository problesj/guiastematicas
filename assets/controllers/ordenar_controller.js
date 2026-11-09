import { Controller } from '@hotwired/stimulus';

/*
 * Arrastrar y soltar en el editor de guías (SortableJS, cargado solo en esta página).
 * Cada lista lleva data-tipo (pestanas, secciones, bloques), data-padre (pestaña o sección) y sus elementos data-id.
 * Las listas con el mismo data-grupo comparten elementos (un bloque puede pasar a otra sección).
 * Al soltar se envía el nuevo orden: {tipo, padre, ids}.
 */
export default class extends Controller {
    static targets = ['lista'];
    static values = { url: String, token: String, error: String };

    async connect() {
        const { default: Sortable } = await import('sortablejs');
        this.ordenables = this.listaTargets.map((lista) => Sortable.create(lista, {
            group: lista.dataset.grupo || lista.dataset.tipo + '-' + (lista.dataset.padre || ''),
            handle: '.asa',
            ghostClass: 'ordenar-fantasma',
            chosenClass: 'ordenar-elegido',
            draggable: '[data-id]',
            animation: 150,
            onSort: () => this.guardar(lista),
        }));
    }

    disconnect() {
        (this.ordenables || []).forEach((o) => o.destroy());
    }

    async guardar(lista) {
        const ids = [...lista.children].filter((e) => e.dataset.id).map((e) => Number(e.dataset.id));
        const respuesta = await fetch(this.urlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
            body: JSON.stringify({ tipo: lista.dataset.tipo, padre: lista.dataset.padre ? Number(lista.dataset.padre) : null, ids }),
        }).catch(() => null);
        if (!respuesta || !respuesta.ok) {
            window.alert(this.errorValue);
            window.location.reload();
        }
    }
}
