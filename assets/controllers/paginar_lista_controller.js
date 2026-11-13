import { Controller } from '@hotwired/stimulus';

/*
 * Lista breve de un bloque Libros o Revistas en la guía, paginada en el navegador: muestra «tamaño» elementos por
 * página con Anterior / Siguiente. Sin JavaScript se ven todos.
 */
export default class extends Controller {
    static targets = ['lista', 'paginas', 'anterior', 'siguiente', 'estado'];
    static values = { tamano: Number, rango: String, pagina: String };

    connect() {
        this.actual = 0;
        this.paginasTarget.hidden = false;
        this.mostrar();
    }

    items() {
        return [...this.listaTarget.children];
    }

    anterior() {
        this.actual--;
        this.mostrar(true);
    }

    siguiente() {
        this.actual++;
        this.mostrar(true);
    }

    mostrar(moverFoco = false) {
        const items = this.items();
        const tamano = this.tamanoValue || 10;
        const paginas = Math.max(1, Math.ceil(items.length / tamano));
        this.actual = Math.min(Math.max(0, this.actual), paginas - 1);
        const desde = this.actual * tamano;
        const hasta = Math.min(desde + tamano, items.length);
        items.forEach((item, i) => { item.hidden = i < desde || i >= hasta; });

        const rango = this.rangoValue.replace('%desde%', String(desde + 1)).replace('%hasta%', String(hasta)).replace('%total%', String(items.length));
        const pagina = this.paginaValue.replace('%pagina%', String(this.actual + 1)).replace('%paginas%', String(paginas));
        this.estadoTarget.textContent = `${pagina} · ${rango}`;
        this.anteriorTarget.disabled = this.actual === 0;
        this.siguienteTarget.disabled = this.actual >= paginas - 1;

        if (moverFoco) {
            // Si el botón pulsado quedó deshabilitado, el foco pasa al otro; la lista vuelve a quedar a la vista.
            const boton = this.anteriorTarget.disabled ? this.siguienteTarget : (this.siguienteTarget.disabled ? this.anteriorTarget : null);
            boton?.focus();
            if (this.listaTarget.getBoundingClientRect().top < 0) {
                this.listaTarget.scrollIntoView({ block: 'start' });
            }
        }
    }
}
