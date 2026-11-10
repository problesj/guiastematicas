import { Controller } from '@hotwired/stimulus';

/* Portada de un libro: si Open Library no la tiene (o no responde), se deja ver la portada genérica con el título. */
export default class extends Controller {
    connect() {
        if (this.element.complete) {
            this.revisar();
        }
    }

    revisar() {
        if (this.element.naturalWidth < 10) {
            this.element.hidden = true;
        }
    }
}
