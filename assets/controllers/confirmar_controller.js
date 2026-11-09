import { Controller } from '@hotwired/stimulus';

/*
 * Pide confirmación antes de enviar un formulario (por ejemplo, eliminar un registro).
 * Uso: <form data-controller="confirmar" data-confirmar-mensaje-value="¿Eliminar?" data-action="confirmar#preguntar">
 */
export default class extends Controller {
    static values = { mensaje: String };

    preguntar(evento) {
        if (!window.confirm(this.mensajeValue)) {
            evento.preventDefault();
        }
    }
}
