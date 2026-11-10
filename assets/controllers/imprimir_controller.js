import { Controller } from '@hotwired/stimulus';

/* Botón "Imprimir" del pie de la guía (la hoja de estilos de impresión oculta la navegación). */
export default class extends Controller {
    imprimir() {
        window.print();
    }
}
