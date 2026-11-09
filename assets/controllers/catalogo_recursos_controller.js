import { Controller } from '@hotwired/stimulus';

/*
 * Catálogo para elegir recursos de un bloque de guía: filtra por texto, tipo y materia, lista los resultados con
 * casillas y envía los marcados al selector-lista que lo contiene (evento "catalogo-recursos:agregar").
 * Los recursos que ya están en el bloque aparecen marcados y deshabilitados.
 */
export default class extends Controller {
    static targets = ['texto', 'tipo', 'materia', 'estado', 'lista', 'todos', 'agregar', 'mas'];
    static values = { url: String, resumen: String, vacio: String, ya: String, error: String };

    connect() {
        this.campo = this.element.closest('[data-controller~="selector-lista"]')?.querySelector('[data-selector-lista-target="campo"]');
        this.alCambiar = () => this.marcarYaAgregados();
        this.campo?.addEventListener('change', this.alCambiar);
        this.pagina = 1;
        this.filtrarYa();
    }

    disconnect() {
        this.campo?.removeEventListener('change', this.alCambiar);
        clearTimeout(this.espera);
        this.controlador?.abort();
    }

    filtrarConEspera() {
        clearTimeout(this.espera);
        this.espera = setTimeout(() => this.filtrarYa(), 300);
    }

    filtrarYa() {
        clearTimeout(this.espera);
        this.pagina = 1;
        this.cargar(false);
    }

    verMas() {
        this.pagina += 1;
        this.cargar(true);
    }

    async cargar(agregarAlFinal) {
        this.controlador?.abort();
        this.controlador = new AbortController();
        const parametros = new URLSearchParams({ q: this.textoTarget.value.trim(), tipo: this.tipoTarget.value, materia: this.materiaTarget.value, pagina: String(this.pagina) });
        let datos;
        try {
            const respuesta = await fetch(`${this.urlValue}?${parametros}`, { headers: { Accept: 'application/json' }, signal: this.controlador.signal });
            if (!respuesta.ok) {
                throw new Error(String(respuesta.status));
            }
            datos = await respuesta.json();
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.estadoTarget.textContent = this.errorValue;
            }
            return;
        }
        if (!agregarAlFinal) {
            this.listaTarget.replaceChildren();
            this.todosTarget.checked = false;
        }
        datos.elementos.forEach((recurso) => this.listaTarget.append(this.fila(recurso)));
        this.estadoTarget.textContent = datos.total === 0 ? this.vacioValue : this.resumenValue.replace('%mostrados%', String(this.listaTarget.children.length)).replace('%total%', String(datos.total));
        this.masTarget.hidden = datos.pagina >= datos.paginas;
        this.marcarYaAgregados();
    }

    fila(recurso) {
        const item = document.createElement('li');
        const etiqueta = document.createElement('label');
        const casilla = document.createElement('input');
        casilla.type = 'checkbox';
        casilla.value = String(recurso.id);
        casilla.dataset.nombre = recurso.nombre;
        casilla.addEventListener('change', () => this.actualizarBoton());
        const texto = document.createElement('span');
        const nombre = document.createElement('strong');
        nombre.textContent = recurso.nombre;
        texto.append(nombre);
        if (recurso.detalle) {
            const detalle = document.createElement('small');
            detalle.textContent = recurso.detalle;
            texto.append(detalle);
        }
        const marca = document.createElement('small');
        marca.className = 'catalogo-recursos__ya';
        marca.textContent = this.yaValue;
        marca.hidden = true;
        texto.append(marca);
        etiqueta.append(casilla, texto);
        item.append(etiqueta);
        return item;
    }

    elegidos() {
        try {
            return new Set(JSON.parse(this.campo?.value || '[]').filter((e) => e.id).map((e) => e.id));
        } catch {
            return new Set();
        }
    }

    marcarYaAgregados() {
        const ya = this.elegidos();
        this.casillas().forEach((casilla) => {
            const agregado = ya.has(Number(casilla.value));
            casilla.disabled = agregado;
            if (agregado) {
                casilla.checked = true;
            } else if (casilla.closest('li').classList.contains('catalogo-recursos__fila--ya')) {
                casilla.checked = false;
            }
            casilla.closest('li').classList.toggle('catalogo-recursos__fila--ya', agregado);
            casilla.closest('label').querySelector('.catalogo-recursos__ya').hidden = !agregado;
        });
        this.actualizarBoton();
    }

    marcarTodos() {
        this.casillas().filter((c) => !c.disabled).forEach((c) => { c.checked = this.todosTarget.checked; });
        this.actualizarBoton();
    }

    marcados() {
        return this.casillas().filter((c) => c.checked && !c.disabled);
    }

    actualizarBoton() {
        this.agregarTarget.disabled = this.marcados().length === 0;
    }

    agregar() {
        const elementos = this.marcados().map((c) => ({ id: Number(c.value), nombre: c.dataset.nombre }));
        if (elementos.length === 0) {
            return;
        }
        this.dispatch('agregar', { detail: { elementos } });
        this.todosTarget.checked = false;
    }

    casillas() {
        return [...this.listaTarget.querySelectorAll('input[type="checkbox"]')];
    }
}
