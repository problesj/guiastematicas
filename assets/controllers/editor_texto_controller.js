import { Controller } from '@hotwired/stimulus';

/*
 * Convierte un <textarea> en un editor CKEditor 5 (WYSIWYG). Sin JavaScript queda el textarea con HTML.
 * Las opciones se limitan a lo que el saneador de HTML del servidor conserva (app.contenido_guia).
 */
export default class extends Controller {
    async connect() {
        const {
            ClassicEditor, Essentials, Paragraph, Heading, Bold, Italic, Underline, Link, List, BlockQuote,
            Table, TableToolbar, Autoformat, PasteFromOffice, RemoveFormat,
        } = await import('ckeditor5');
        const idioma = document.documentElement.lang === 'en' ? 'en' : 'es';
        const traducciones = idioma === 'es' ? [(await import('ckeditor5/translations/es.js')).default] : [];

        this.editor = await ClassicEditor.create(this.element, {
            licenseKey: 'GPL',
            language: idioma,
            translations: traducciones,
            plugins: [Essentials, Paragraph, Heading, Bold, Italic, Underline, Link, List, BlockQuote, Table, TableToolbar, Autoformat, PasteFromOffice, RemoveFormat],
            toolbar: ['heading', '|', 'bold', 'italic', 'underline', 'link', '|', 'bulletedList', 'numberedList', 'blockQuote', 'insertTable', '|', 'removeFormat', 'undo', 'redo'],
            heading: {
                options: [
                    { model: 'paragraph', title: 'Párrafo', class: 'ck-heading_paragraph' },
                    { model: 'heading4', view: 'h4', title: 'Título', class: 'ck-heading_heading4' },
                    { model: 'heading5', view: 'h5', title: 'Subtítulo', class: 'ck-heading_heading5' },
                ],
            },
            link: { addTargetToExternalLinks: true, defaultProtocol: 'https://' },
            table: { contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells'] },
        });
        // El formulario envía el textarea: se mantiene al día con el contenido del editor.
        this.element.form?.addEventListener('submit', () => this.editor.updateSourceElement());
    }

    disconnect() {
        this.editor?.destroy();
    }
}
