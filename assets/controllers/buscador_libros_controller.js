import { Controller } from '@hotwired/stimulus';

/*
 * Buscador del bloque Libros: busca en el catálogo de la biblioteca (Primo VE, por título exacto, autor y editorial)
 * o en Biblioges (bibliografía de una carrera o asignatura), muestra los resultados con casillas y agrega los marcados
 * a la lista de libros del bloque (controlador "coleccion" que lo contiene), con sus datos ya completados.
 * Nada se guarda hasta «Guardar»; los libros que ya están en la lista aparecen marcados y deshabilitados.
 */
export default class extends Controller {
    static targets = ['panel', 'titulo', 'autor', 'editorial', 'modo', 'codigo', 'etiquetaCodigo', 'carreras', 'tipo', 'origen',
        'ultima', 'opcionUltima', 'boton', 'estado', 'acciones', 'todos', 'agregar', 'lista', 'mas'];

    static values = {
        catalogoUrl: String, bibliogesUrl: String, carrerasUrl: String, buscando: String, resumen: String, vacio: String,
        falta: String, error: String, noEncontrado: String, noAutorizado: String, ya: String, agregados: String,
        biblioteca: String, tipos: Object,
    };

    static CAMPOS = ['isbn', 'doi', 'titulo', 'autor', 'editorial', 'anio', 'enlace'];

    connect() {
        this.libros = [];
        this.pagina = 1;
        this.cambiarFuente();
    }

    disconnect() {
        this.controlador?.abort();
    }

    fuente() {
        return this.element.querySelector('input[name="buscador-libros-fuente"]:checked')?.value
            ?? this.panelTargets[0]?.dataset.fuente;
    }

    cambiarFuente() {
        const fuente = this.fuente();
        this.panelTargets.forEach((panel) => { panel.hidden = panel.dataset.fuente !== fuente; });
        this.limpiar();
        if (fuente === 'biblioges') {
            this.cargarCarreras();
        }
    }

    cambiarModo() {
        const modo = this.modoTarget.value;
        this.etiquetaCodigoTarget.textContent = this.etiquetaCodigoTarget.dataset[modo];
        this.codigoTarget.setAttribute('list', modo === 'carrera' ? 'buscador-carreras' : '');
        this.codigoTarget.value = '';
    }

    // «Solo la última edición» se aplica solo a la bibliografía disponible.
    cambiarOrigen() {
        this.opcionUltimaTarget.hidden = this.origenTarget.value !== 'disponibles';
    }

    async cargarCarreras() {
        if (this.carrerasCargadas || !this.hasCarrerasTarget) {
            return;
        }
        this.carrerasCargadas = true;
        try {
            const respuesta = await fetch(this.carrerasUrlValue, { headers: { Accept: 'application/json' } });
            const datos = respuesta.ok ? await respuesta.json() : { carreras: [] };
            this.carrerasTarget.replaceChildren(...datos.carreras.map((c) => {
                const opcion = document.createElement('option');
                opcion.value = c.codigo;
                opcion.label = [c.nombre, c.sede].filter(Boolean).join(' · ');
                opcion.textContent = opcion.label;
                return opcion;
            }));
        } catch {
            this.carrerasCargadas = false;
        }
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
        const fuente = this.fuente();
        const parametros = fuente === 'catalogo'
            ? { titulo: this.tituloTarget.value.trim(), autor: this.autorTarget.value.trim(), editorial: this.editorialTarget.value.trim(), pagina: String(this.pagina) }
            : { modo: this.modoTarget.value, codigo: this.codigoTarget.value.trim(), tipo: this.tipoTarget.value, fuente: this.origenTarget.value, ultima: this.ultimaTarget.checked ? '1' : '0' };
        const criterios = fuente === 'catalogo' ? [parametros.titulo, parametros.autor, parametros.editorial] : [parametros.codigo];
        if (!criterios.some(Boolean)) {
            this.avisar(this.faltaValue);
            return;
        }

        this.controlador?.abort();
        this.controlador = new AbortController();
        this.botonTarget.disabled = true;
        this.avisar(this.buscandoValue);
        let datos;
        try {
            const url = fuente === 'catalogo' ? this.catalogoUrlValue : this.bibliogesUrlValue;
            const respuesta = await fetch(`${url}?${new URLSearchParams(parametros)}`, { headers: { Accept: 'application/json' }, signal: this.controlador.signal });
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
            this.avisar({ no_encontrado: this.noEncontradoValue, no_autorizado: this.noAutorizadoValue, parametros: this.faltaValue }[datos.error] ?? this.errorValue);
            return;
        }

        if (!agregarAlFinal) {
            this.limpiar();
        }
        const grupos = fuente === 'catalogo' ? [{ nombre: null, libros: datos.libros }] : datos.grupos;
        grupos.forEach((grupo) => this.mostrarGrupo(grupo));
        const mostrados = this.libros.length;
        const total = fuente === 'catalogo' ? Math.max(datos.total, mostrados) : mostrados;
        this.avisar(mostrados === 0 ? this.vacioValue : this.resumenValue.replace('%mostrados%', String(mostrados)).replace('%total%', String(total)));
        this.masTarget.hidden = fuente !== 'catalogo' || datos.pagina >= datos.paginas;
        this.listaTarget.hidden = mostrados === 0;
        this.accionesTarget.hidden = mostrados === 0;
        this.marcarYaAgregados();
    }

    mostrarGrupo(grupo) {
        if (grupo.libros.length === 0) {
            return;
        }
        if (grupo.nombre) {
            const titulo = document.createElement('h3');
            titulo.className = 'buscador-libros__grupo';
            titulo.textContent = grupo.nombre;
            this.listaTarget.append(titulo);
        }
        const lista = document.createElement('ul');
        grupo.libros.forEach((libro) => lista.append(this.fila(libro)));
        this.listaTarget.append(lista);
    }

    fila(libro) {
        const indice = this.libros.push(libro) - 1;
        const item = document.createElement('li');
        const etiqueta = document.createElement('label');
        const casilla = document.createElement('input');
        casilla.type = 'checkbox';
        casilla.value = String(indice);
        casilla.addEventListener('change', () => this.actualizarBoton());
        const texto = document.createElement('span');
        const nombre = document.createElement('strong');
        nombre.textContent = libro.titulo;
        texto.append(nombre);
        const detalle = [
            libro.autor,
            [libro.editorial, libro.anio].filter(Boolean).join(', '),
            libro.isbn ? `ISBN ${libro.isbn}` : null,
            libro.tipo_bibliografia ? this.tiposValue[libro.tipo_bibliografia] ?? libro.tipo_bibliografia : null,
            libro.local ? this.bibliotecaValue : null,
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
        this.libros = [];
        this.listaTarget.replaceChildren();
        this.listaTarget.hidden = true;
        this.accionesTarget.hidden = true;
        this.masTarget.hidden = true;
        this.todosTarget.checked = false;
        this.avisar('');
        this.actualizarBoton();
    }

    // Libros que ya están en la lista del bloque, por ISBN o por título.
    enElBloque() {
        const claves = new Set();
        this.coleccion()?.itemTargets.forEach((item) => {
            const isbn = this.campo(item, 'isbn')?.value.replace(/[^0-9X]/gi, '').toUpperCase();
            const titulo = this.campo(item, 'titulo')?.value.trim().toLowerCase();
            if (isbn) claves.add(`isbn:${isbn}`);
            if (titulo) claves.add(`titulo:${titulo}`);
        });
        return claves;
    }

    yaEsta(libro, claves) {
        return (libro.isbn && claves.has(`isbn:${libro.isbn}`)) || claves.has(`titulo:${libro.titulo.trim().toLowerCase()}`);
    }

    marcarYaAgregados() {
        const claves = this.enElBloque();
        this.casillas().forEach((casilla) => {
            const ya = this.yaEsta(this.libros[Number(casilla.value)], claves);
            casilla.disabled = ya;
            casilla.checked = ya || (casilla.checked && !casilla.closest('li').classList.contains('catalogo-recursos__fila--ya'));
            casilla.closest('li').classList.toggle('catalogo-recursos__fila--ya', ya);
            casilla.closest('label').querySelector('.catalogo-recursos__ya').hidden = !ya;
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
            const libro = this.libros[Number(casilla.value)];
            coleccion.agregar();
            const item = coleccion.listaTarget.lastElementChild;
            this.constructor.CAMPOS.forEach((nombre) => {
                const campo = this.campo(item, nombre);
                if (campo && libro[nombre]) {
                    campo.value = libro[nombre];
                }
            });
        });
        this.todosTarget.checked = false;
        this.marcarYaAgregados();
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
