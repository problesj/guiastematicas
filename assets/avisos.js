import Swal from 'sweetalert2';

/*
 * Avisos y confirmaciones del sitio con SweetAlert2, con los botones y colores de la plataforma. Reemplazan a
 * window.alert y window.confirm. Los textos de los botones van en el idioma de la página.
 */
const TEXTOS = {
    es: { aceptar: 'Aceptar', cancelar: 'Cancelar', confirmar: 'Sí, continuar' },
    en: { aceptar: 'OK', cancelar: 'Cancel', confirmar: 'Yes, continue' },
};

function textos() {
    return TEXTOS[document.documentElement.lang] ?? TEXTOS.es;
}

const base = Swal.mixin({
    buttonsStyling: false,
    // El diálogo se anuncia con su propio texto (no lleva título visible).
    didOpen: (popup) => {
        const texto = popup.querySelector('.swal2-html-container');
        if (texto) {
            texto.id ||= 'aviso-emergente-texto';
            popup.setAttribute('aria-labelledby', texto.id);
        }
    },
    reverseButtons: true,
    focusCancel: false,
    customClass: {
        popup: 'aviso-emergente',
        confirmButton: 'boton',
        cancelButton: 'boton boton--secundario',
        actions: 'aviso-emergente__acciones',
    },
});

/** Pregunta y resuelve true si la persona confirma. */
export async function confirmar(mensaje, { peligro = false, boton = null } = {}) {
    const t = textos();
    const resultado = await base.fire({
        text: mensaje,
        icon: peligro ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: boton ?? t.confirmar,
        cancelButtonText: t.cancelar,
        customClass: {
            popup: 'aviso-emergente',
            confirmButton: peligro ? 'boton boton--peligro' : 'boton',
            cancelButton: 'boton boton--secundario',
            actions: 'aviso-emergente__acciones',
        },
    });

    return resultado.isConfirmed;
}

/** Muestra un aviso (tipo: error, success, warning, info) y espera a que se cierre. */
export async function avisar(mensaje, tipo = 'info') {
    await base.fire({ text: mensaje, icon: tipo, confirmButtonText: textos().aceptar });
}
