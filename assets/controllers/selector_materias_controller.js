import { Controller } from '@hotwired/stimulus';

/*
 * Selector compacto de materias: muestra las marcadas como casillas y un buscador (combobox) que agrega otras desde el
 * catálogo de materias (se pide una sola vez por página). Para listas largas, donde repetir todas las casillas por
 * fila sería muy pesado. Otros controladores pueden agregar materias con el evento «selector-materias:agregar».
 */
const catalogos = new Map();

function normalizar(texto) {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

export default class extends Controller {
    static targets = ['marcadas', 'entrada', 'lista', 'estado'];
    static values = { url: String, nombre: String, casilla: Object, sinResultados: String, resultados: String };

    connect() {
        this.activa = -1;
        this.opciones = [];
    }

    catalogo() {
        if (!catalogos.has(this.urlValue)) {
            catalogos.set(this.urlValue, fetch(this.urlValue, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : []))
                .then((lista) => lista.map((m) => ({ ...m, busqueda: normalizar(`${m.etiqueta} ${m.texto ?? ''}`) })))
                .catch(() => []));
        }

        return catalogos.get(this.urlValue);
    }

    marcadasIds() {
        return [...this.marcadasTarget.querySelectorAll('input[type="checkbox"]:checked')].map((c) => String(c.value));
    }

    async buscar() {
        const consulta = normalizar(this.entradaTarget.value);
        if (consulta.length < 2) {
            this.cerrar();
            return;
        }
        const ya = this.marcadasIds();
        const palabras = consulta.split(/\s+/);
        this.opciones = (await this.catalogo())
            .filter((m) => !ya.includes(String(m.id)) && palabras.every((p) => m.busqueda.includes(p)))
            .slice(0, 12);
        this.mostrar();
    }

    mostrar() {
        const lista = this.listaTarget;
        lista.replaceChildren();
        this.activa = -1;
        this.opciones.forEach((m, i) => {
            const opcion = document.createElement('li');
            opcion.id = `${lista.id}-${i}`;
            opcion.setAttribute('role', 'option');
            opcion.setAttribute('aria-selected', 'false');
            opcion.textContent = m.etiqueta;
            if (m.campo) {
                const campo = document.createElement('small');
                campo.textContent = m.campo;
                opcion.append(' ', campo);
            }
            opcion.addEventListener('mousedown', (e) => { e.preventDefault(); this.elegir(i); });
            lista.append(opcion);
        });
        const hay = this.opciones.length > 0;
        lista.hidden = !hay;
        this.entradaTarget.setAttribute('aria-expanded', String(hay));
        this.entradaTarget.removeAttribute('aria-activedescendant');
        if (this.hasEstadoTarget) {
            this.estadoTarget.textContent = hay ? this.resultadosValue.replace('%n%', String(this.opciones.length)) : this.sinResultadosValue;
        }
    }

    tecla(evento) {
        if (this.listaTarget.hidden) {
            return;
        }
        if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
            evento.preventDefault();
            const n = this.opciones.length;
            this.activa = (this.activa + (evento.key === 'ArrowDown' ? 1 : n - 1)) % n;
            [...this.listaTarget.children].forEach((li, i) => li.setAttribute('aria-selected', String(i === this.activa)));
            this.entradaTarget.setAttribute('aria-activedescendant', `${this.listaTarget.id}-${this.activa}`);
        } else if (evento.key === 'Enter') {
            evento.preventDefault();
            this.elegir(this.activa >= 0 ? this.activa : 0);
        } else if (evento.key === 'Escape') {
            evento.preventDefault();
            this.cerrar();
        }
    }

    elegir(indice) {
        const materia = this.opciones[indice];
        if (!materia) {
            return;
        }
        this.agregar(String(materia.id), materia.etiqueta);
        this.entradaTarget.value = '';
        this.cerrar();
        this.entradaTarget.focus();
    }

    agregarExterno(evento) {
        this.agregar(String(evento.detail.id), evento.detail.etiqueta);
    }

    agregar(id, etiqueta) {
        const existente = [...this.marcadasTarget.querySelectorAll('input[type="checkbox"]')].find((c) => c.value === id);
        if (existente) {
            existente.checked = true;
            existente.dispatchEvent(new Event('change', { bubbles: true }));
            return;
        }
        const etiquetaEl = document.createElement('label');
        etiquetaEl.className = 'casillas__opcion';
        const casilla = document.createElement('input');
        casilla.type = 'checkbox';
        casilla.value = id;
        casilla.checked = true;
        if (this.nombreValue) {
            casilla.name = this.nombreValue;
        }
        Object.entries(this.casillaValue).forEach(([atributo, valor]) => casilla.setAttribute(atributo, valor));
        etiquetaEl.append(casilla, ` ${etiqueta}`);
        this.marcadasTarget.append(etiquetaEl);
        // Después de que Stimulus conecte las acciones de la casilla nueva (data-action en «casilla»).
        setTimeout(() => casilla.dispatchEvent(new Event('change', { bubbles: true })), 0);
    }

    cerrar() {
        this.listaTarget.hidden = true;
        this.listaTarget.replaceChildren();
        this.entradaTarget.setAttribute('aria-expanded', 'false');
        this.entradaTarget.removeAttribute('aria-activedescendant');
        this.opciones = [];
    }
}
