import { Controller } from '@hotwired/stimulus';

/* Filtra por texto la lista de guías al elegir una desde Moodle. */
export default class extends Controller {
    static targets = ['texto', 'guia', 'vacio'];

    filtrar() {
        const buscado = this.textoTarget.value.trim().toLowerCase();
        let visibles = 0;
        this.guiaTargets.forEach((guia) => {
            const coincide = buscado === '' || guia.dataset.texto.includes(buscado);
            guia.hidden = !coincide;
            visibles += coincide ? 1 : 0;
        });
        this.vacioTarget.hidden = visibles > 0;
    }
}
