import { Controller } from '@hotwired/stimulus';

/*
 * Botón «Buscar datos» de una revista: con el ISSN (impreso o electrónico) pide al servidor los datos de la búsqueda de
 * revistas del catálogo y completa título, editorial y el otro ISSN (nada se guarda hasta «Guardar»).
 */
export default class extends Controller {
    static targets = ['issn', 'eissn', 'titulo', 'editorial', 'boton', 'estado'];
    static values = { url: String, buscando: String, falta: String, vacio: String, error: String, listo: String };

    async buscar() {
        const issn = this.issnTarget.value.trim() || this.eissnTarget.value.trim();
        if (!issn) {
            this.avisar(this.faltaValue, true);
            this.issnTarget.focus();
            return;
        }

        this.botonTarget.disabled = true;
        this.avisar(this.buscandoValue);
        try {
            const respuesta = await fetch(`${this.urlValue}?${new URLSearchParams({ issn })}`, { headers: { Accept: 'application/json' } });
            if (404 === respuesta.status) {
                this.avisar(this.vacioValue, true);
                return;
            }
            if (!respuesta.ok) {
                throw new Error(String(respuesta.status));
            }
            const datos = await respuesta.json();
            for (const campo of ['titulo', 'editorial']) {
                if (datos[campo]) {
                    this[`${campo}Target`].value = datos[campo];
                }
            }
            // El ISSN buscado queda donde estaba; el otro (impreso o electrónico) completa el campo vacío.
            const limpio = (valor) => (valor ?? '').replace(/[^0-9X]/gi, '').toUpperCase();
            const otro = [datos.issn, datos.eissn].find((valor) => valor && limpio(valor) !== limpio(issn)) ?? '';
            const vacio = [this.issnTarget, this.eissnTarget].find((campo) => !campo.value.trim());
            if (vacio && otro) {
                vacio.value = otro;
            }
            this.dispatch('completado');
            this.avisar(this.listoValue);
        } catch {
            this.avisar(this.errorValue, true);
        } finally {
            this.botonTarget.disabled = false;
        }
    }

    avisar(texto, problema = false) {
        this.estadoTarget.textContent = texto;
        this.estadoTarget.classList.toggle('libro-form__estado--problema', problema);
    }
}
