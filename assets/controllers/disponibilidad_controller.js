import { Controller } from '@hotwired/stimulus';

/* Disponibilidad de los libros de un bloque (Alma), pedida después de cargar la página para no demorarla. */
export default class extends Controller {
    static targets = ['libro'];
    static values = { url: String };

    async connect() {
        try {
            const respuesta = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
            if (!respuesta.ok) {
                return;
            }
            const estados = await respuesta.json();
            this.libroTargets.forEach((libro) => {
                const estado = estados[libro.dataset.mms];
                if (estado) {
                    libro.textContent = estado.texto;
                    libro.classList.add(estado.disponible ? 'libro__disponibilidad--si' : 'libro__disponibilidad--no');
                }
            });
        } catch {
            // Sin disponibilidad: el libro se muestra igual.
        }
    }
}
