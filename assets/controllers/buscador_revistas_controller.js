import { Controller } from '@hotwired/stimulus';

/*
 * Buscador del bloque Revistas: busca en la búsqueda de revistas del catálogo (Primo VE) por palabras del título y/o
 * por categoría (o un enlace copiado de esa búsqueda en Primo), muestra los resultados con casillas y agrega los marcados
 * a la lista de revistas del bloque (controlador "coleccion" que lo contiene), con sus datos ya completados.
 * Nada se guarda hasta «Guardar»; las revistas que ya están en la lista aparecen marcadas y deshabilitadas.
 */
export default class extends Controller {
    static targets = ['texto', 'categoria', 'boton', 'estado', 'acciones', 'todos', 'agregar', 'lista', 'mas'];

    static values = {
        url: String, buscando: String, resumen: String, vacio: String, falta: String, error: String, ya: String, agregados: String,
    };

    static CAMPOS = ['issn', 'eissn', 'titulo', 'editorial', 'enlace'];

    connect() {
        this.revistas = [];
        this.pagina = 1;
    }

    disconnect() {
        this.controlador?.abort();
    }

    buscar() {
        this.pagina = 1;
        this.cargar(false);
    }

    verMas() {
        this.pagina += 1;
        this.cargar(true);
    }

    async cargar(agregarAlFinal) {
        const parametros = { texto: this.textoTarget.value.trim(), categoria: this.categoriaTarget.value.trim(), pagina: String(this.pagina) };
        if (!parametros.texto && !parametros.categoria) {
            this.avisar(this.faltaValue);
            return;
        }

        this.controlador?.abort();
        this.controlador = new AbortController();
        this.botonTarget.disabled = true;
        this.avisar(this.buscandoValue);
        let datos;
        try {
            const respuesta = await fetch(`${this.urlValue}?${new URLSearchParams(parametros)}`, { headers: { Accept: 'application/json' }, signal: this.controlador.signal });
            datos = await respuesta.json().catch(() => ({ error: 'sin_respuesta' }));
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.avisar(this.errorValue);
            }
            return;
        } finally {
            this.botonTarget.disabled = false;
        }
        if (datos.error) {
            this.limpiar();
            this.avisar(datos.error === 'parametros' ? this.faltaValue : [this.errorValue, datos.detalle ? `(Primo: ${datos.detalle})` : ''].join(' ').trim());
            return;
        }

        if (!agregarAlFinal) {
            this.limpiar();
        }
        datos.revistas.forEach((revista) => this.listaTarget.append(this.fila(revista)));
        const mostradas = this.revistas.length;
        this.avisar(mostradas === 0 ? this.vacioValue : this.resumenValue.replace('%mostrados%', String(mostradas)).replace('%total%', String(Math.max(datos.total, mostradas))));
        this.masTarget.hidden = datos.pagina >= datos.paginas;
        this.listaTarget.hidden = mostradas === 0;
        this.accionesTarget.hidden = mostradas === 0;
        this.marcarYaAgregadas();
    }

    fila(revista) {
        const indice = this.revistas.push(revista) - 1;
        const item = document.createElement('li');
        const etiqueta = document.createElement('label');
        const casilla = document.createElement('input');
        casilla.type = 'checkbox';
        casilla.value = String(indice);
        casilla.addEventListener('change', () => this.actualizarBoton());
        const texto = document.createElement('span');
        const nombre = document.createElement('strong');
        nombre.textContent = revista.titulo;
        texto.append(nombre);
        const detalle = [
            revista.editorial,
            revista.issn ? `ISSN ${revista.issn}` : null,
            revista.eissn ? `e-ISSN ${revista.eissn}` : null,
        ].filter(Boolean).join(' · ');
        if (detalle) {
            const pequeno = document.createElement('small');
            pequeno.textContent = detalle;
            texto.append(pequeno);
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

    limpiar() {
        this.revistas = [];
        this.listaTarget.replaceChildren();
        this.listaTarget.hidden = true;
        this.accionesTarget.hidden = true;
        this.masTarget.hidden = true;
        this.todosTarget.checked = false;
        this.avisar('');
        this.actualizarBoton();
    }

    // Revistas que ya están en la lista del bloque, por ISSN o por título.
    enElBloque() {
        const claves = new Set();
        this.coleccion()?.itemTargets.forEach((item) => {
            ['issn', 'eissn'].forEach((nombre) => {
                const issn = this.limpio(this.campo(item, nombre)?.value);
                if (issn) claves.add(`issn:${issn}`);
            });
            const titulo = this.campo(item, 'titulo')?.value.trim().toLowerCase();
            if (titulo) claves.add(`titulo:${titulo}`);
        });
        return claves;
    }

    limpio(valor) {
        return (valor ?? '').replace(/[^0-9X]/gi, '').toUpperCase();
    }

    yaEsta(revista, claves) {
        return [revista.issn, revista.eissn].some((issn) => issn && claves.has(`issn:${this.limpio(issn)}`))
            || claves.has(`titulo:${revista.titulo.trim().toLowerCase()}`);
    }

    marcarYaAgregadas() {
        const claves = this.enElBloque();
        this.casillas().forEach((casilla) => {
            const fila = casilla.closest('li');
            const ya = this.yaEsta(this.revistas[Number(casilla.value)], claves);
            casilla.disabled = ya;
            casilla.checked = ya || (casilla.checked && !fila.classList.contains('catalogo-recursos__fila--ya'));
            fila.classList.toggle('catalogo-recursos__fila--ya', ya);
            fila.querySelector('.catalogo-recursos__ya').hidden = !ya;
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
        const coleccion = this.coleccion();
        const marcados = this.marcados();
        if (!coleccion || marcados.length === 0) {
            return;
        }
        marcados.forEach((casilla) => {
            const revista = this.revistas[Number(casilla.value)];
            coleccion.agregar();
            const item = coleccion.listaTarget.lastElementChild;
            this.constructor.CAMPOS.forEach((nombre) => {
                const campo = this.campo(item, nombre);
                if (campo && revista[nombre]) {
                    campo.value = revista[nombre];
                }
            });
        });
        this.todosTarget.checked = false;
        this.marcarYaAgregadas();
        this.avisar(this.agregadosValue.replace('%count%', String(marcados.length)));
        this.agregarTarget.focus();
    }

    coleccion() {
        const elemento = this.element.closest('[data-controller~="coleccion"]');
        return elemento ? this.application.getControllerForElementAndIdentifier(elemento, 'coleccion') : null;
    }

    campo(item, nombre) {
        return item.querySelector(`[name$="[${nombre}]"]`);
    }

    casillas() {
        return [...this.listaTarget.querySelectorAll('input[type="checkbox"]')];
    }

    avisar(texto) {
        this.estadoTarget.textContent = texto;
    }
}
