import { Controller } from '@hotwired/stimulus';

/*
 * Lista de elementos de un formulario (CollectionType de Symfony): agregar desde la plantilla, quitar y ordenar.
 * Al enviar, renumera los nombres en el orden de la pantalla para que el servidor reciba ese orden.
 */
export default class extends Controller {
    static targets = ['lista', 'plantilla', 'item'];
    static values = { indice: Number };

    connect() {
        this.formulario = this.element.closest('form');
        this.renumerar = this.renumerar.bind(this);
        this.formulario?.addEventListener('submit', this.renumerar);
    }

    disconnect() {
        this.formulario?.removeEventListener('submit', this.renumerar);
    }

    agregar() {
        const html = this.plantillaTarget.innerHTML.replace(/__name__/g, String(this.indiceValue++));
        this.listaTarget.insertAdjacentHTML('beforeend', html);
        this.listaTarget.lastElementChild?.querySelector('input:not([type="hidden"])')?.focus();
    }

    quitar(evento) {
        const item = this.item(evento);
        const vecino = item.nextElementSibling ?? item.previousElementSibling;
        item.remove();
        (vecino?.querySelector('button') ?? this.element.querySelector('[data-action~="coleccion#agregar"]'))?.focus();
    }

    subir(evento) {
        const item = this.item(evento);
        if (item.previousElementSibling) {
            item.previousElementSibling.before(item);
            evento.currentTarget.focus();
        }
    }

    bajar(evento) {
        const item = this.item(evento);
        if (item.nextElementSibling) {
            item.nextElementSibling.after(item);
            evento.currentTarget.focus();
        }
    }

    renumerar() {
        this.itemTargets.forEach((item, i) => {
            item.querySelectorAll('[name]').forEach((campo) => {
                campo.name = campo.name.replace(/\[items\]\[[^\]]+\]/, `[items][${i}]`);
            });
        });
    }

    item(evento) {
        return evento.currentTarget.closest('[data-coleccion-target="item"]');
    }
}
