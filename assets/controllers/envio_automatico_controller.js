import { Controller } from '@hotwired/stimulus';

/* Envía el formulario al cambiar un campo (p. ej. "por página"); sin JavaScript queda el botón visible. */
export default class extends Controller {
    static targets = ['boton'];

    connect() {
        this.botonTargets.forEach((boton) => boton.hidden = true);
    }

    enviar() {
        this.element.requestSubmit();
    }
}
