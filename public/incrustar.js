/*
 * Guías temáticas · Bibliotecas UCN: muestra bloques de una guía dentro de otra web.
 *
 *   <div data-guias-bloque="12"></div>
 *   <script src="https://<servidor>/incrustar.js" async></script>
 *
 * Opcional: data-idioma="en" (por omisión, el de la guía es "es"). Cada bloque se carga en un iframe que se ajusta a
 * su contenido.
 */
(function () {
    'use strict';
    var script = document.currentScript;
    if (!script) return;
    var origen = new URL(script.src).origin;
    var marcos = [];

    document.querySelectorAll('[data-guias-bloque]').forEach(function (contenedor) {
        var id = parseInt(contenedor.getAttribute('data-guias-bloque'), 10);
        if (!id || contenedor.querySelector('iframe')) return;
        var idioma = contenedor.getAttribute('data-idioma') === 'en' ? 'en' : 'es';
        var marco = document.createElement('iframe');
        marco.src = origen + '/' + idioma + '/incrustar/bloque/' + id;
        marco.title = contenedor.getAttribute('data-titulo') || (idioma === 'en' ? 'Library guide' : 'Guía temática');
        marco.loading = 'lazy';
        marco.style.cssText = 'display:block;width:100%;height:320px;border:0;';
        contenedor.appendChild(marco);
        marcos.push(marco);
    });

    window.addEventListener('message', function (evento) {
        if (evento.origin !== origen || !evento.data || evento.data.tipo !== 'guias-ucn-altura') return;
        marcos.forEach(function (marco) {
            if (marco.contentWindow === evento.source) {
                marco.style.height = Math.min(Math.max(parseInt(evento.data.altura, 10) || 0, 80), 5000) + 'px';
            }
        });
    });
})();
