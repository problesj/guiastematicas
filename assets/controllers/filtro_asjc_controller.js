import { Controller } from '@hotwired/stimulus';

/* Buscador del selector de categorías ASJC: muestra solo las que coinciden (por nombre o código) y abre sus campos. */
export default class extends Controller {
    static targets = ['campo', 'estado'];
    static values = { resultados: String };

    static normalizar(texto) {
        return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }

    filtrar(evento) {
        const consulta = this.constructor.normalizar(evento.target.value);
        let total = 0;
        this.campoTargets.forEach((campo) => {
            let visibles = 0;
            campo.querySelectorAll('.casillas__opcion').forEach((opcion) => {
                const coincide = consulta === '' || this.constructor.normalizar(opcion.textContent).includes(consulta);
                opcion.hidden = !coincide;
                visibles += coincide ? 1 : 0;
            });
            campo.hidden = visibles === 0;
            if (consulta !== '') {
                campo.open = visibles > 0;
            } else {
                campo.open = campo.querySelector('input:checked') !== null;
            }
            total += visibles;
        });
        this.estadoTarget.textContent = consulta === '' ? '' : this.resultadosValue.replace('%n%', String(total));
    }
}
