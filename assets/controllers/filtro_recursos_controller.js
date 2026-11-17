import { Controller } from '@hotwired/stimulus';

/*
 * Filtro por tipo de recurso de un bloque Recursos bibliográficos con muchos recursos (en la guía). Los botones
 * funcionan como un grupo de opciones (aria-pressed); sin JavaScript la barra queda oculta y se ven todos.
 */
export default class extends Controller {
    static targets = ['barra', 'lista', 'estado'];
    static values = { estado: String };

    connect() {
        this.barraTarget.hidden = false;
    }

    elegir(evento) {
        const boton = evento.currentTarget;
        const tipo = boton.dataset.tipo;
        this.barraTarget.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === boton)));
        const items = [...this.listaTarget.children];
        let visibles = 0;
        items.forEach((item) => {
            item.hidden = '' !== tipo && item.dataset.tipo !== tipo;
            visibles += item.hidden ? 0 : 1;
        });
        this.estadoTarget.textContent = this.estadoValue.replace('%visibles%', String(visibles)).replace('%total%', String(items.length));
    }
}
