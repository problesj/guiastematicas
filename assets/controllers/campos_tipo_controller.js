import { Controller } from '@hotwired/stimulus';

/*
 * Muestra solo los datos bibliográficos que corresponden al tipo de recurso elegido.
 * Cada campo lleva data-tipos="libros revistas ..."; sin JavaScript se ven todos.
 * Uso: <fieldset data-controller="campos-tipo" data-campos-tipo-selector-value="#id_del_select">
 */
export default class extends Controller {
    static targets = ['campo', 'vacio'];
    static values = { selector: String };

    connect() {
        this.select = document.querySelector(this.selectorValue);
        if (!this.select) {
            return;
        }
        this.actualizar = this.actualizar.bind(this);
        this.select.addEventListener('change', this.actualizar);
        this.actualizar();
    }

    disconnect() {
        this.select?.removeEventListener('change', this.actualizar);
    }

    actualizar() {
        const tipo = this.select.value;
        let visibles = 0;
        this.campoTargets.forEach((campo) => {
            const corresponde = campo.dataset.tipos.split(' ').includes(tipo);
            campo.hidden = !corresponde;
            if (corresponde) {
                visibles++;
            }
        });
        this.vacioTargets.forEach((aviso) => {
            aviso.hidden = visibles > 0;
        });
    }
}
