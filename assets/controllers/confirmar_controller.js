import { Controller } from '@hotwired/stimulus';
import { confirmar } from '../avisos.js';

/*
 * Pide confirmación (SweetAlert2) antes de enviar un formulario, por ejemplo al eliminar un registro.
 * Uso: <form data-controller="confirmar" data-confirmar-mensaje-value="¿Eliminar?" data-action="submit->confirmar#preguntar">
 * Opcional: data-confirmar-boton-value (texto del botón que confirma) y data-confirmar-peligro-value="true".
 */
export default class extends Controller {
    static values = { mensaje: String, boton: String, peligro: Boolean };

    async preguntar(evento) {
        if (this.confirmado) {
            this.confirmado = false;
            return;
        }
        evento.preventDefault();
        const boton = evento.submitter ?? null;
        if (await confirmar(this.mensajeValue, { peligro: this.peligroValue, boton: this.botonValue || null })) {
            this.confirmado = true;
            this.element.requestSubmit(boton);
        }
    }
}
