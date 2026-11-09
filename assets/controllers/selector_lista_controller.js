import { Controller } from '@hotwired/stimulus';

/*
 * Lista ordenada elegida con búsqueda (autores de un recurso, recursos de un bloque de guía).
 * Busca en url?q=... (JSON [{id, nombre, detalle}]) y, si "nuevos" está activo, permite agregar uno nuevo escrito como
 * "Apellidos, Nombres". La lista, en orden, se guarda como JSON en un campo oculto: [{id, nombre}] o [{apellidos, nombres}].
 */
export default class extends Controller {
    static targets = ['campo', 'lista', 'busqueda', 'sugerencias'];
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

    cerrar() {
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
                this.boton('↑', this.subirValue, nombre.textContent, () => this.mover(i, i - 1), i === 0),
                this.boton('↓', this.bajarValue, nombre.textContent, () => this.mover(i, i + 1), i === this.elegidos.length - 1),
                this.boton('×', this.quitarValue, nombre.textContent, () => {
                    this.elegidos.splice(i, 1);
                    this.dibujar();
                }),
            );
            lista.append(item);
        });
    }

    boton(simbolo, accion, nombre, alHacerClic, deshabilitado = false) {
        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'selector-autores__boton';
        boton.textContent = simbolo;
        boton.title = `${accion}: ${nombre}`;
        boton.setAttribute('aria-label', `${accion}: ${nombre}`);
        boton.disabled = deshabilitado;
        boton.addEventListener('click', alHacerClic);
        return boton;
    }
}
