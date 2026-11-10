import { Controller } from '@hotwired/stimulus';

/* Carrusel del bloque "Diapositivas": desplazamiento con scroll-snap y botones Anterior / Siguiente. */
export default class extends Controller {
    static targets = ['pista', 'contador'];
    static values = { contador: String };

    anterior() {
        this.ir(this.actual() - 1);
    }

    siguiente() {
        this.ir(this.actual() + 1);
    }

    ir(indice) {
        const total = this.pistaTarget.children.length;
        const destino = (indice + total) % total; // vuelve al principio después de la última
        this.pistaTarget.scrollTo({ left: destino * this.pistaTarget.clientWidth, behavior: 'smooth' });
    }

    actual() {
        return Math.round(this.pistaTarget.scrollLeft / Math.max(1, this.pistaTarget.clientWidth));
    }

    actualizar() {
        if (this.hasContadorTarget) {
            this.contadorTarget.textContent = this.contadorValue
                .replace('%actual%', String(this.actual() + 1))
                .replace('%total%', String(this.pistaTarget.children.length));
        }
    }
}
