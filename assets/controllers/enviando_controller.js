import { Controller } from '@hotwired/stimulus';

/*
 * Botón de envío de un formulario que tarda (p. ej. el asistente de guías con IA): al enviar, el botón se deshabilita
 * y muestra el texto de espera, que también se anuncia a los lectores de pantalla.
 * Uso: <button type="submit" data-controller="enviando" data-enviando-texto-value="Armando…">
 */
export default class extends Controller {
    static values = { texto: String };

    connect() {
        this.alEnviar = (evento) => {
            if (evento.defaultPrevented) {
                return;
            }
            // Se deshabilita después del envío para que el formulario incluya el botón.
            setTimeout(() => {
                this.element.disabled = true;
                this.element.textContent = this.textoValue;
                this.element.setAttribute('aria-live', 'polite');
            }, 0);
        };
        this.element.form?.addEventListener('submit', this.alEnviar);
    }

    disconnect() {
        this.element.form?.removeEventListener('submit', this.alEnviar);
    }
}
