import { Controller } from '@hotwired/stimulus';

/*
 * Lista ordenada elegida con búsqueda (autores de un recurso, recursos de un bloque de guía).
 * Busca en url?q=... (JSON [{id, nombre, detalle}]) y, si "nuevos" está activo, permite agregar uno nuevo escrito como
 * "Apellidos, Nombres". La lista, en orden, se guarda como JSON en un campo oculto: [{id, nombre}] o [{apellidos, nombres}].
 */
// Los mismos trazos que App\Twig\IconosAccion.
const ICONOS = {
    subir: '<path d="M12 19V5M6 11l6-6 6 6"/>',
    bajar: '<path d="M12 5v14M6 13l6 6 6-6"/>',
    eliminar: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/>',
};

export default class extends Controller {
    static targets = ['campo', 'lista', 'busqueda', 'sugerencias'];
    // La búsqueda con sugerencias es opcional (el bloque de recursos usa el catálogo con filtros).
    static values = { url: String, agregar: String, nuevo: String, quitar: String, subir: String, bajar: String, vacio: String, nuevos: { type: Boolean, default: true } };

    connect() {
        try {
            this.elegidos = JSON.parse(this.campoTarget.value || '[]');
        } catch {
            this.elegidos = [];
        }
        if (!Array.isArray(this.elegidos)) {
            this.elegidos = [];
        }
        this.opciones = [];
        this.activa = -1;
        this.cerrarFuera = (evento) => {
            if (!this.element.contains(evento.target)) {
                this.cerrar();
            }
        };
        document.addEventListener('click', this.cerrarFuera);
        this.dibujar();
    }

    disconnect() {
        document.removeEventListener('click', this.cerrarFuera);
        clearTimeout(this.espera);
        this.controlador?.abort();
    }

    buscar() {
        clearTimeout(this.espera);
        const texto = this.busquedaTarget.value.trim();
        if (texto.length < 2) {
            this.cerrar();
            return;
        }
        this.espera = setTimeout(() => this.consultar(texto), 250);
    }

    async consultar(texto) {
        this.controlador?.abort();
        this.controlador = new AbortController();
        let encontrados = [];
        try {
            const respuesta = await fetch(`${this.urlValue}?q=${encodeURIComponent(texto)}`, {
                headers: { Accept: 'application/json' },
                signal: this.controlador.signal,
            });
            if (respuesta.ok) {
                encontrados = await respuesta.json();
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
        }
        const ya = new Set(this.elegidos.filter((a) => a.id).map((a) => a.id));
        this.opciones = encontrados.filter((a) => !ya.has(a.id)).map((a) => ({ id: a.id, nombre: a.nombre, detalle: a.detalle || a.orcid || '' }));
        const [apellidos, ...resto] = texto.split(',');
        const nuevo = { apellidos: apellidos.trim(), nombres: resto.join(',').trim() };
        const repetido = encontrados.some((a) => a.nombre.toLowerCase() === this.nombre(nuevo).toLowerCase());
        if (this.nuevosValue && nuevo.apellidos && !repetido) {
            this.opciones.push({ ...nuevo, nombre: this.agregarValue.replace('%nombre%', this.nombre(nuevo)), nuevo: true });
        }
        this.mostrarOpciones();
    }

    mostrarOpciones() {
        const lista = this.sugerenciasTarget;
        lista.replaceChildren();
        this.opciones.forEach((opcion, i) => {
            const item = document.createElement('li');
            item.id = `${this.busquedaTarget.id}-opcion-${i}`;
            item.setAttribute('role', 'option');
            item.className = 'selector-autores__opcion' + (opcion.nuevo ? ' selector-autores__opcion--nueva' : '');
            item.textContent = opcion.nombre;
            if (opcion.detalle) {
                const detalle = document.createElement('small');
                detalle.textContent = ` ${opcion.detalle}`;
                item.append(detalle);
            }
            item.addEventListener('mousedown', (evento) => evento.preventDefault());
            item.addEventListener('click', () => this.elegir(i));
            lista.append(item);
        });
        this.activa = this.opciones.length ? 0 : -1;
        this.marcarActiva();
        lista.hidden = this.opciones.length === 0;
        this.busquedaTarget.setAttribute('aria-expanded', String(!lista.hidden));
    }

    tecla(evento) {
        const abierta = !this.sugerenciasTarget.hidden;
        if (evento.key === 'ArrowDown' && abierta) {
            evento.preventDefault();
            this.activa = (this.activa + 1) % this.opciones.length;
            this.marcarActiva();
        } else if (evento.key === 'ArrowUp' && abierta) {
            evento.preventDefault();
            this.activa = (this.activa - 1 + this.opciones.length) % this.opciones.length;
            this.marcarActiva();
        } else if (evento.key === 'Enter') {
            // Enter nunca envía el formulario desde este campo.
            evento.preventDefault();
            if (abierta && this.activa >= 0) {
                this.elegir(this.activa);
            }
        } else if (evento.key === 'Escape') {
            this.cerrar();
        }
    }

    marcarActiva() {
        [...this.sugerenciasTarget.children].forEach((item, i) => item.setAttribute('aria-selected', String(i === this.activa)));
        if (this.activa >= 0) {
            this.busquedaTarget.setAttribute('aria-activedescendant', `${this.busquedaTarget.id}-opcion-${this.activa}`);
        } else {
            this.busquedaTarget.removeAttribute('aria-activedescendant');
        }
    }

    elegir(i) {
        const opcion = this.opciones[i];
        if (!opcion) {
            return;
        }
        this.elegidos.push(opcion.nuevo ? { apellidos: opcion.apellidos, nombres: opcion.nombres } : { id: opcion.id, nombre: opcion.nombre });
        this.busquedaTarget.value = '';
        this.cerrar();
        this.dibujar();
        this.busquedaTarget.focus();
    }

    /** Agrega varios elementos ya existentes ({id, nombre}) al final, sin repetir. Lo usa el catálogo de recursos. */
    agregarVarios({ detail }) {
        const ya = new Set(this.elegidos.filter((e) => e.id).map((e) => e.id));
        (detail?.elementos || []).filter((e) => e.id && !ya.has(e.id)).forEach((e) => this.elegidos.push({ id: e.id, nombre: e.nombre }));
        this.dibujar();
    }

    cerrar() {
        if (!this.hasSugerenciasTarget || !this.hasBusquedaTarget) {
            return;
        }
        this.sugerenciasTarget.hidden = true;
        this.busquedaTarget.setAttribute('aria-expanded', 'false');
        this.busquedaTarget.removeAttribute('aria-activedescendant');
    }

    mover(desde, hasta) {
        if (hasta < 0 || hasta >= this.elegidos.length) {
            return;
        }
        const [autor] = this.elegidos.splice(desde, 1);
        this.elegidos.splice(hasta, 0, autor);
        this.dibujar();
    }

    nombre(autor) {
        if (autor.nombre && autor.id) {
            return autor.nombre;
        }
        return autor.nombres ? `${autor.apellidos}, ${autor.nombres}` : autor.apellidos;
    }

    dibujar() {
        this.campoTarget.value = JSON.stringify(this.elegidos);
        this.campoTarget.dispatchEvent(new Event('change', { bubbles: true }));
        const lista = this.listaTarget;
        lista.replaceChildren();
        if (this.elegidos.length === 0) {
            const vacio = document.createElement('li');
            vacio.className = 'selector-autores__vacio';
            vacio.textContent = this.vacioValue;
            lista.append(vacio);
            return;
        }
        this.elegidos.forEach((autor, i) => {
            const item = document.createElement('li');
            item.className = 'selector-autores__autor';
            const nombre = document.createElement('span');
            nombre.textContent = this.nombre(autor);
            item.append(nombre);
            if (!autor.id) {
                const marca = document.createElement('small');
                marca.className = 'etiqueta';
                marca.textContent = this.nuevoValue;
                item.append(marca);
            }
            item.append(
                this.boton('subir', this.subirValue, nombre.textContent, () => this.mover(i, i - 1), i === 0),
                this.boton('bajar', this.bajarValue, nombre.textContent, () => this.mover(i, i + 1), i === this.elegidos.length - 1),
                this.boton('eliminar', this.quitarValue, nombre.textContent, () => {
                    this.elegidos.splice(i, 1);
                    this.dibujar();
                }),
            );
            lista.append(item);
        });
    }

    boton(icono, accion, nombre, alHacerClic, deshabilitado = false) {
        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = `accion selector-autores__boton${icono === 'eliminar' ? ' accion--peligro' : ''}`;
        boton.innerHTML = `<svg class="accion__icono" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${ICONOS[icono]}</svg>`;
        boton.dataset.tooltip = accion;
        boton.setAttribute('aria-label', `${accion}: ${nombre}`);
        boton.disabled = deshabilitado;
        boton.addEventListener('click', alHacerClic);
        return boton;
    }
}
