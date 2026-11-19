(() => {
  const menu = document.querySelector('.barra__menu'), lateral = document.getElementById('lateral');
  menu.addEventListener('click', () => { const abierto = lateral.classList.toggle('abierto'); menu.setAttribute('aria-expanded', abierto); });
  // Ampliar capturas
  document.querySelectorAll('article img').forEach((img) => img.addEventListener('click', () => {
    const visor = document.createElement('div'); visor.className = 'visor'; visor.setAttribute('role', 'dialog');
    visor.setAttribute('aria-label', img.alt); visor.innerHTML = '<img alt="">'; visor.firstChild.src = img.src; visor.firstChild.alt = img.alt;
    const cerrar = () => visor.remove(); visor.addEventListener('click', cerrar);
    document.addEventListener('keydown', function esc(e) { if (e.key === 'Escape') { cerrar(); document.removeEventListener('keydown', esc); } });
    document.body.appendChild(visor);
  }));
  // Buscador (índice en busqueda.js)
  const campo = document.getElementById('buscar'), caja = document.getElementById('resultados');
  const normal = (t) => t.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const escapar = (t) => t.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  campo.addEventListener('input', () => {
    const q = normal(campo.value.trim());
    if (q.length < 2) { caja.hidden = true; return; }
    const palabras = q.split(/\s+/);
    const hallados = INDICE_MANUAL.filter((s) => { const t = normal(s.titulo + ' ' + s.texto); return palabras.every((p) => t.includes(p)); }).slice(0, 12);
    caja.innerHTML = hallados.length ? hallados.map((s) => {
      const t = normal(s.texto), i = Math.max(0, t.indexOf(palabras[0]) - 60);
      let frag = escapar(s.texto.slice(i, i + 180));
      return `<a href="${s.url}"><strong>${escapar(s.titulo)}</strong>${i > 0 ? '…' : ''}${frag}…</a>`;
    }).join('') : '<p>Sin resultados.</p>';
    caja.hidden = false;
  });
  campo.addEventListener('keydown', (e) => { if (e.key === 'Escape') { caja.hidden = true; } if (e.key === 'Enter') { const a = caja.querySelector('a'); if (a) location.href = a.href; } });
  document.addEventListener('click', (e) => { if (!e.target.closest('.buscador')) caja.hidden = true; });
})();
