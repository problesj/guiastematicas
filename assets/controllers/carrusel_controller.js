import { Controller } from '@hotwired/stimulus';

/* Carrusel de libros: varias portadas a la vista; los botones avanzan de a una pantalla y se apagan en los extremos. */
export default class extends Controller {
    static targets = ['pista', 'anterior', 'siguiente'];

    connect() {
        this.actualizar();
    }

    anterior() {
        this.pistaTarget.scrollBy({ left: -this.paso(), behavior: 'smooth' });
    }

    siguiente() {
        this.pistaTarget.scrollBy({ left: this.paso(), behavior: 'smooth' });
    }

    paso() {
        return Math.max(1, this.pistaTarget.clientWidth * 0.9);
    }

    actualizar() {
        const pista = this.pistaTarget;
        if (this.hasAnteriorTarget) {
            this.anteriorTarget.disabled = pista.scrollLeft <= 2;
        }
        if (this.hasSiguienteTarget) {
            this.siguienteTarget.disabled = pista.scrollLeft + pista.clientWidth >= pista.scrollWidth - 2;
        }
    }
}
