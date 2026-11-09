import { Controller } from '@hotwired/stimulus';

/*
 * Permite cambiar el ancho de las columnas de una tabla arrastrando el borde derecho de cada encabezado
 * (o con las flechas izquierda y derecha cuando el borde tiene el foco).
 * Uso: <table data-controller="columnas-ajustables">
 */
export default class extends Controller {
    connect() {
        this.encabezados = [...this.element.querySelectorAll('thead th')];
        this.encabezados.forEach((th, i) => {
            if (i === this.encabezados.length - 1) {
                return;
            }
            const asa = document.createElement('span');
            asa.className = 'ajustador-columna';
            asa.setAttribute('role', 'separator');
            asa.setAttribute('aria-orientation', 'vertical');
            asa.setAttribute('aria-label', `${th.textContent.trim()}: ancho de columna`);
            asa.tabIndex = 0;
            asa.addEventListener('pointerdown', (e) => this.iniciar(e, th));
            asa.addEventListener('keydown', (e) => this.teclado(e, th));
            th.appendChild(asa);
        });
    }

    fijarAnchos() {
        if (this.element.classList.contains('tabla--anchos-fijos')) {
            return;
        }
        const anchos = this.encabezados.map((th) => th.getBoundingClientRect().width);
        this.encabezados.forEach((th, i) => {
            th.style.width = `${anchos[i]}px`;
        });
        this.element.style.width = `${anchos.reduce((a, b) => a + b, 0)}px`;
        this.element.classList.add('tabla--anchos-fijos');
    }

    cambiar(th, delta) {
        const actual = th.getBoundingClientRect().width;
        const nuevo = Math.max(60, actual + delta);
        th.style.width = `${nuevo}px`;
        this.element.style.width = `${this.element.getBoundingClientRect().width + (nuevo - actual)}px`;
    }

    iniciar(evento, th) {
        evento.preventDefault();
        this.fijarAnchos();
        let x = evento.clientX;
        const mover = (e) => {
            this.cambiar(th, e.clientX - x);
            x = e.clientX;
        };
        const soltar = () => {
            window.removeEventListener('pointermove', mover);
            window.removeEventListener('pointerup', soltar);
        };
        window.addEventListener('pointermove', mover);
        window.addEventListener('pointerup', soltar);
    }

    teclado(evento, th) {
        if (evento.key !== 'ArrowLeft' && evento.key !== 'ArrowRight') {
            return;
        }
        evento.preventDefault();
        this.fijarAnchos();
        this.cambiar(th, evento.key === 'ArrowRight' ? 20 : -20);
    }
}
