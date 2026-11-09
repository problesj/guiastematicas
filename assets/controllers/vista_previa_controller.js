import { Controller } from '@hotwired/stimulus';

/*
 * Vista previa de la importación: junta lo elegido en cada fila (importar, URL, proxy, materias) en un solo campo
 * JSON "seleccion", para no depender del límite de campos de PHP con archivos grandes.
 */
export default class extends Controller {
    static targets = ['fila', 'seleccion', 'boton', 'todas', 'resumen'];
    static values = { uno: String, varios: String, ninguno: String };

    connect() {
        this.contar();
    }

    marcarTodas(evento) {
        this.filaTargets.forEach((fila) => {
            fila.querySelector('[data-campo="importar"]').checked = evento.target.checked;
        });
        this.contar();
    }

    contar() {
        const marcadas = this.filaTargets.filter((fila) => fila.querySelector('[data-campo="importar"]').checked);
        this.filaTargets.forEach((fila) => {
            fila.classList.toggle('fila-importacion--descartada', !fila.querySelector('[data-campo="importar"]').checked);
            const resumen = fila.querySelector('[data-vista-previa-target="resumen"]');
            if (resumen) {
                const n = fila.querySelectorAll('[data-campo="materia"]:checked').length;
                resumen.textContent = resumen.textContent.replace(/\d+/, String(n));
            }
        });
        if (this.hasTodasTarget) {
            this.todasTarget.checked = marcadas.length === this.filaTargets.length;
            this.todasTarget.indeterminate = marcadas.length > 0 && marcadas.length < this.filaTargets.length;
        }
        if (this.hasBotonTarget) {
            const n = marcadas.length;
            this.botonTarget.textContent = n === 0 ? this.ningunoValue : (n === 1 ? this.unoValue : this.variosValue).replace('%count%', n);
            this.botonTarget.disabled = n === 0;
        }
    }

    enviar() {
        const seleccion = {};
        this.filaTargets.forEach((fila) => {
            const url = fila.querySelector('[data-campo="url"]:checked');
            seleccion[fila.dataset.numero] = {
                importar: fila.querySelector('[data-campo="importar"]').checked,
                proxy: fila.querySelector('[data-campo="proxy"]').checked,
                materias: [...fila.querySelectorAll('[data-campo="materia"]:checked')].map(
                    (m) => ('nueva' in m.dataset ? m.value : Number(m.value)),
                ),
                ...(url ? { url: url.value } : {}),
            };
        });
        this.seleccionTarget.value = JSON.stringify(seleccion);
        // Solo viaja el JSON: las casillas y opciones de cada fila no se envían.
        this.element.querySelectorAll('[data-campo]').forEach((campo) => {
            campo.disabled = true;
        });
    }
}
