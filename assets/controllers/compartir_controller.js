import { Controller } from '@hotwired/stimulus';

/* Compartir la guía: menú nativo del sistema (si el navegador lo ofrece) y copiar el enlace al portapapeles. */
export default class extends Controller {
    static targets = ['nativo', 'estado'];
    static values = { url: String, titulo: String };

    connect() {
        if (this.hasNativoTarget && typeof navigator.share === 'function') {
            this.nativoTarget.hidden = false;
        }
    }

    async nativo() {
        try {
            await navigator.share({ title: this.tituloValue, url: this.urlValue });
        } catch (e) {
            // La persona cerró el menú: no hay nada que avisar.
        }
    }

    async copiar() {
        let ok = false;
        try {
            await navigator.clipboard.writeText(this.urlValue);
            ok = true;
        } catch (e) {
            ok = false;
        }
        this.estadoTarget.textContent = ok ? this.estadoTarget.dataset.copiado : `${this.estadoTarget.dataset.error} ${this.urlValue}`;
    }
}
