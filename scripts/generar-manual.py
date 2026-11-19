#!/usr/bin/env python3
"""Genera en HTML (estilo wiki) y en Word la documentación escrita en Markdown en docs/manual o docs/tecnico.

Uso: python3 scripts/generar-manual.py [manual|tecnico]   (necesita pandoc 3 y, para Word, python-docx y Pillow)

- docs/<carpeta>/html/*.html : una página por capítulo, con menú lateral, índice de la página y buscador.
- docs/<carpeta>/<nombre>.docx : todo el documento en un archivo, con portada e índice.

Las imágenes quedan en docs/<carpeta>/img y las páginas HTML las enlazan como ../img/..., así que la carpeta se puede
copiar entera a cualquier servidor o abrir desde el disco. Los bloques Mermaid (que GitHub dibuja) se omiten en HTML y
Word, que llevan los diagramas como imágenes.
"""

from __future__ import annotations

import html
import json
import re
import shutil
import subprocess
import sys
import tempfile
import unicodedata
from datetime import date
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
FORMATO = 'markdown+gfm_auto_identifiers'
DOCUMENTOS = {
    'manual': {
        'carpeta': 'manual', 'docx': 'Manual-Guias-tematicas-UCN.docx', 'nombre': 'Manual',
        'marca': 'Manual · Guías temáticas', 'sufijo': 'Manual de Guías temáticas UCN',
        'titulo': 'Manual de uso', 'subtitulo': 'Guías temáticas · Bibliotecas UCN',
        'intro': 'Cómo usar este manual', 'buscar': 'Buscar en el manual',
    },
    'tecnico': {
        'carpeta': 'tecnico', 'docx': 'Documento-tecnico-Guias-tematicas-UCN.docx', 'nombre': 'Documento técnico',
        'marca': 'Documento técnico · Guías temáticas', 'sufijo': 'Documento técnico de Guías temáticas UCN',
        'titulo': 'Documento técnico', 'subtitulo': 'Guías temáticas · Bibliotecas UCN · Para desarrolladores',
        'intro': 'Acerca de este documento', 'buscar': 'Buscar en el documento',
    },
}
DOC = DOCUMENTOS['manual']
MANUAL = SALIDA_HTML = DOCX = None
CAPITULOS: list[Path] = []


def elegir(clave: str) -> None:
    global DOC, MANUAL, SALIDA_HTML, DOCX, CAPITULOS
    DOC = DOCUMENTOS[clave]
    MANUAL = RAIZ / 'docs' / DOC['carpeta']
    SALIDA_HTML = MANUAL / 'html'
    DOCX = MANUAL / DOC['docx']
    CAPITULOS = sorted(p for p in MANUAL.glob('[0-9][0-9]-*.md'))


MERMAID = re.compile(r'```mermaid\n.*?```\n?', re.S)


def sin_mermaid(md: str) -> str:
    return MERMAID.sub('*(El diagrama Mermaid se ve en la versión Markdown de GitHub; aquí están las imágenes del capítulo 2.)*\n', md)


def pandoc(texto: str, *argumentos: str) -> str:
    return subprocess.run(['pandoc', '-f', FORMATO, *argumentos], input=texto, capture_output=True, text=True,
                          check=True, cwd=MANUAL).stdout


def identificador(titulo: str) -> str:
    """Mismo identificador que GitHub y pandoc (gfm_auto_identifiers) para un título."""
    texto = titulo.strip().lower()
    texto = ''.join(c for c in texto if c.isalnum() or c in ' -_' or unicodedata.category(c).startswith('M'))
    return texto.replace(' ', '-')


def titulo_de(md: str) -> str:
    return re.search(r'^# (.+)$', md, re.M).group(1).strip()


# ---------------------------------------------------------------------------------------------------- HTML wiki

PLANTILLA = """<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{titulo} · {sufijo}</title>
<link rel="stylesheet" href="wiki.css">
</head>
<body>
<header class="barra">
  <button class="barra__menu" aria-controls="lateral" aria-expanded="false">☰ <span>Menú</span></button>
  <a class="barra__marca" href="index.html">{marca} <small>Bibliotecas UCN</small></a>
  <form class="buscador" role="search" onsubmit="return false">
    <label for="buscar" class="oculto">{buscar}</label>
    <input id="buscar" type="search" placeholder="{buscar}…" autocomplete="off">
    <div id="resultados" class="buscador__resultados" hidden></div>
  </form>
</header>
<div class="cuerpo">
  <nav id="lateral" class="lateral" aria-label="Capítulos">
    <p class="lateral__titulo">Capítulos</p>
    <ul>{menu}</ul>
  </nav>
  <main class="contenido">
    <p class="migas"><a href="index.html">{nombre}</a>{miga}</p>
    <article>{cuerpo}</article>
    <nav class="paginacion" aria-label="Capítulos anterior y siguiente">{paginacion}</nav>
    <footer class="pie">Bibliotecas UCN · {nombre} generado el {fecha} a partir de <code>docs/{carpeta}</code>.</footer>
  </main>
  <aside class="indice" aria-label="En esta página">{indice}</aside>
</div>
<script src="busqueda.js"></script>
<script src="wiki.js"></script>
</body>
</html>
"""

CSS = """:root { --principal: #9a602c; --principal-oscuro: #7a4a20; --suave: #f7f1ea; --borde: #e3d6c6; --texto: #1d1d1b;
  --secundario: #555; --fondo: #fff; --codigo: #f4f1ec; }
@media (prefers-color-scheme: dark) { :root { --principal: #e0a86f; --principal-oscuro: #f0c08f; --suave: #2a2420;
  --borde: #4a3f36; --texto: #ece6df; --secundario: #b8afa6; --fondo: #1a1714; --codigo: #2a2420; } }
* { box-sizing: border-box; }
body { margin: 0; font: 16px/1.6 "Source Sans 3", "Segoe UI", Roboto, Arial, sans-serif; color: var(--texto); background: var(--fondo); }
a { color: var(--principal); } a:hover { color: var(--principal-oscuro); }
.oculto { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.barra { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 1rem; padding: .6rem 1rem;
  background: var(--principal); color: #fff; }
.barra a { color: #fff; text-decoration: none; }
.barra__marca { font-weight: 700; font-size: 1.1rem; } .barra__marca small { font-weight: 400; opacity: .85; margin-left: .3rem; }
.barra__menu { display: none; background: none; border: 1px solid rgba(255,255,255,.6); color: #fff; border-radius: 6px; padding: .3rem .6rem; font: inherit; cursor: pointer; }
.buscador { position: relative; margin-left: auto; width: min(22rem, 45vw); }
.buscador input { width: 100%; padding: .45rem .7rem; border-radius: 6px; border: 0; font: inherit; }
.buscador__resultados { position: absolute; right: 0; top: 2.6rem; width: min(30rem, 92vw); max-height: 70vh; overflow: auto;
  background: var(--fondo); color: var(--texto); border: 1px solid var(--borde); border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,.18); }
.buscador__resultados a { display: block; padding: .6rem .8rem; border-bottom: 1px solid var(--borde); color: var(--texto); text-decoration: none; }
.buscador__resultados a:hover, .buscador__resultados a:focus { background: var(--suave); }
.buscador__resultados strong { color: var(--principal); display: block; }
.buscador__resultados mark { background: #f3d9a8; color: #1d1d1b; }
.buscador__resultados p { margin: 0; padding: .6rem .8rem; color: var(--secundario); }
.cuerpo { display: grid; grid-template-columns: 16rem minmax(0, 1fr) 14rem; gap: 2rem; max-width: 92rem; margin: 0 auto; }
.lateral { position: sticky; top: 3.4rem; align-self: start; max-height: calc(100vh - 3.4rem); overflow: auto; padding: 1.5rem 0 1.5rem 1rem; }
.lateral__titulo { font-size: .8rem; text-transform: uppercase; letter-spacing: .06em; color: var(--secundario); margin: 0 0 .5rem; font-weight: 700; }
.lateral ul { list-style: none; margin: 0; padding: 0; }
.lateral li a { display: block; padding: .4rem .7rem; border-radius: 6px; text-decoration: none; color: var(--texto); }
.lateral li a:hover { background: var(--suave); }
.lateral li a[aria-current] { background: var(--suave); color: var(--principal); font-weight: 700; border-left: 3px solid var(--principal); }
.contenido { padding: 1.5rem 0 3rem; min-width: 0; }
.migas { font-size: .9rem; color: var(--secundario); margin: 0 0 1rem; }
article h1 { font-size: 2.1rem; line-height: 1.2; margin: 0 0 1rem; }
article h2 { font-size: 1.5rem; margin: 2.2rem 0 .8rem; padding-bottom: .3rem; border-bottom: 2px solid var(--borde); }
article h3 { font-size: 1.2rem; margin: 1.8rem 0 .6rem; }
article h2, article h3 { scroll-margin-top: 4rem; }
.ancla { opacity: 0; margin-left: .4rem; text-decoration: none; } h2:hover .ancla, h3:hover .ancla { opacity: .7; }
article img { max-width: 100%; height: auto; border: 1px solid var(--borde); border-radius: 6px; box-shadow: 0 2px 10px rgba(0,0,0,.06); cursor: zoom-in; }
article figure { margin: 1.2rem 0; } article figcaption { font-size: .9rem; color: var(--secundario); margin-top: .4rem; text-align: center; }
article table { border-collapse: collapse; width: 100%; margin: 1rem 0; font-size: .95rem; display: block; overflow-x: auto; }
article th, article td { border: 1px solid var(--borde); padding: .45rem .6rem; vertical-align: top; text-align: left; }
article th { background: var(--suave); }
article code { background: var(--codigo); padding: .1rem .3rem; border-radius: 4px; font-size: .9em; }
article pre { background: var(--codigo); padding: .8rem 1rem; border-radius: 6px; overflow-x: auto; }
article pre code { background: none; padding: 0; }
article blockquote { margin: 1rem 0; padding: .6rem 1rem; border-left: 4px solid var(--principal); background: var(--suave); border-radius: 0 6px 6px 0; }
article blockquote p { margin: .3rem 0; }
.indice { position: sticky; top: 3.4rem; align-self: start; max-height: calc(100vh - 3.4rem); overflow: auto; padding: 1.5rem 1rem 1.5rem 0; font-size: .9rem; }
.indice p { font-weight: 700; margin: 0 0 .5rem; } .indice ul { list-style: none; padding-left: .8rem; margin: 0; border-left: 2px solid var(--borde); }
.indice > ul { padding-left: .6rem; } .indice li { margin: .25rem 0; } .indice a { text-decoration: none; color: var(--secundario); } .indice a:hover { color: var(--principal); }
.paginacion { display: flex; justify-content: space-between; gap: 1rem; margin-top: 3rem; }
.paginacion a { flex: 1; border: 1px solid var(--borde); border-radius: 8px; padding: .7rem 1rem; text-decoration: none; }
.paginacion a small { display: block; color: var(--secundario); } .paginacion a.siguiente { text-align: right; }
.pie { margin-top: 2.5rem; font-size: .85rem; color: var(--secundario); }
.visor { position: fixed; inset: 0; background: rgba(0,0,0,.82); display: flex; align-items: center; justify-content: center; z-index: 50; cursor: zoom-out; padding: 1rem; }
.visor img { max-width: 100%; max-height: 100%; border-radius: 6px; }
@media (max-width: 1100px) { .cuerpo { grid-template-columns: 15rem minmax(0, 1fr); } .indice { display: none; } }
@media (max-width: 760px) {
  .cuerpo { display: block; padding: 0 16px; } .barra__menu { display: inline-block; } .barra__marca small { display: none; }
  .lateral { display: none; position: fixed; inset: 3.2rem 0 0 0; max-height: none; background: var(--fondo); z-index: 20; padding: 1rem; }
  .lateral.abierto { display: block; } .buscador { width: 100%; } .barra { flex-wrap: wrap; }
}
@media print { .barra, .lateral, .indice, .paginacion { display: none; } .cuerpo { display: block; } }
"""

JS = """(() => {
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
  const normal = (t) => t.toLowerCase().normalize('NFD').replace(/[\\u0300-\\u036f]/g, '');
  const escapar = (t) => t.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  campo.addEventListener('input', () => {
    const q = normal(campo.value.trim());
    if (q.length < 2) { caja.hidden = true; return; }
    const palabras = q.split(/\\s+/);
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
"""


def html_de(nombre_md: str) -> str:
    return 'index.html' if nombre_md == 'README.md' else nombre_md.replace('.md', '.html')


def enlaces_html(md: str) -> str:
    md = re.sub(r'\]\(([0-9][0-9]-[\w-]+|README)\.md(#[^)]*)?\)', lambda m: f']({html_de(m.group(1) + ".md")}{m.group(2) or ""})', md)
    md = md.replace('](img/', '](../img/')
    # Enlaces a la otra documentación (docs/manual ↔ docs/tecnico): a su versión HTML
    md = re.sub(r'\]\(\.\./(manual|tecnico)/([\w-]+)\.md(#[^)]*)?\)',
                lambda m: f'](../../{m.group(1)}/html/{html_de(m.group(2) + ".md")}{m.group(3) or ""})', md)
    # En el índice (README) los formatos apuntan a archivos de la carpeta del documento
    md = md.replace('(`html/index.html`)', '(esta versión)')
    md = md.replace(f'`{DOC["docx"]}`', f'[{DOC["docx"]}](../{DOC["docx"]})')
    return sin_mermaid(md)


def anclas(cuerpo: str) -> str:
    return re.sub(r'<(h[23]) id="([^"]+)"([^>]*)>(.*?)</\1>',
                  lambda m: f'<{m.group(1)} id="{m.group(2)}"{m.group(3)}>{m.group(4)}<a class="ancla" href="#{m.group(2)}" aria-label="Enlace a esta sección">#</a></{m.group(1)}>',
                  cuerpo)


def generar_html() -> None:
    if SALIDA_HTML.exists():
        shutil.rmtree(SALIDA_HTML)
    SALIDA_HTML.mkdir(parents=True)
    paginas = [MANUAL / 'README.md', *CAPITULOS]
    titulos = {p.name: titulo_de(p.read_text(encoding='utf-8')) for p in paginas}
    titulos['README.md'] = 'Inicio'
    indice_busqueda = []

    for n, pagina in enumerate(paginas):
        md = enlaces_html(pagina.read_text(encoding='utf-8'))
        cuerpo = anclas(pandoc(md, '-t', 'html5', '--no-highlight'))
        toc = pandoc(md, '-t', 'html5', '--toc', '--toc-depth=3', '--template', str(MANUAL / 'toc.tmpl')) if pagina.name != 'README.md' else ''
        toc = re.sub(r'^\s*<ul>\s*<li><a href="#[^"]+"[^>]*>[^<]*</a>\s*<ul>', '<ul>', toc, count=1)  # sin el título del capítulo
        menu = ''.join(
            f'<li><a href="{html_de(p.name)}"{" aria-current=\"page\"" if p == pagina else ""}>{html.escape(titulos[p.name])}</a></li>'
            for p in paginas)
        anterior = paginas[n - 1] if n > 0 else None
        siguiente = paginas[n + 1] if n + 1 < len(paginas) else None
        paginacion = (f'<a class="anterior" href="{html_de(anterior.name)}"><small>← Anterior</small>{html.escape(titulos[anterior.name])}</a>' if anterior else '<span></span>') + \
                     (f'<a class="siguiente" href="{html_de(siguiente.name)}"><small>Siguiente →</small>{html.escape(titulos[siguiente.name])}</a>' if siguiente else '')
        (SALIDA_HTML / html_de(pagina.name)).write_text(PLANTILLA.format(
            titulo=html.escape(titulos[pagina.name]),
            sufijo=html.escape(DOC['sufijo']), marca=html.escape(DOC['marca']), buscar=html.escape(DOC['buscar']),
            nombre=html.escape(DOC['nombre']), carpeta=DOC['carpeta'],
            menu=menu,
            miga='' if pagina.name == 'README.md' else f' › {html.escape(titulos[pagina.name])}',
            cuerpo=cuerpo,
            paginacion=paginacion,
            indice=f'<p>En esta página</p>{toc}' if toc.strip() else '',
            fecha=date.today().strftime('%d-%m-%Y'),
        ), encoding='utf-8')

        # Índice de búsqueda: una entrada por sección (##), con su texto plano
        partes = re.split(r'^(#{1,3} .+)$', md, flags=re.M)
        actual = titulos[pagina.name]
        ancla = ''
        for parte in partes:
            if re.match(r'^#{1,3} ', parte):
                actual = parte.lstrip('#').strip()
                ancla = '' if parte.startswith('# ') else '#' + identificador(actual)
                continue
            texto = re.sub(r'\s+', ' ', pandoc(re.sub(r'!\[[^\]]*\]\([^)]*\)', '', parte), '-t', 'plain', '--wrap=none')).strip()
            if texto:
                indice_busqueda.append({'titulo': actual, 'url': html_de(pagina.name) + ancla, 'texto': texto})

    (SALIDA_HTML / 'wiki.css').write_text(CSS, encoding='utf-8')
    (SALIDA_HTML / 'wiki.js').write_text(JS, encoding='utf-8')
    (SALIDA_HTML / 'busqueda.js').write_text('const INDICE_MANUAL = ' + json.dumps(indice_busqueda, ensure_ascii=False) + ';\n', encoding='utf-8')
    print(f'HTML: {SALIDA_HTML} ({len(paginas)} páginas)')


# ---------------------------------------------------------------------------------------------------- Word

def plantilla_word(destino: Path) -> None:
    """Toma la plantilla de pandoc y le pone los colores y la letra del manual."""
    from docx import Document
    from docx.enum.text import WD_ALIGN_PARAGRAPH
    from docx.shared import Pt, RGBColor

    with open(destino, 'wb') as archivo:
        archivo.write(subprocess.run(['pandoc', '--print-default-data-file', 'reference.docx'], capture_output=True, check=True).stdout)
    doc = Document(str(destino))
    # Se buscan por nombre recorriendo la lista: doc.styles['Heading 1'] traduce el nombre y no encuentra los de pandoc.
    estilos = {s.name: s for s in doc.styles}
    cafe = RGBColor(0x9A, 0x60, 0x2C)
    for estilo in doc.styles:
        if getattr(estilo, 'font', None) is not None and estilo.type == 1:
            estilo.font.name = 'Calibri'
    normal = estilos['Normal']
    normal.font.size = Pt(11)
    for nombre, tam in [('Title', 28), ('Subtitle', 14), ('Heading 1', 20), ('Heading 2', 15), ('Heading 3', 12.5)]:
        estilo = estilos[nombre]
        estilo.font.color.rgb = cafe if nombre != 'Subtitle' else RGBColor(0x55, 0x55, 0x55)
        estilo.font.size = Pt(tam)
        estilo.font.bold = nombre != 'Subtitle'
    estilos['Heading 1'].paragraph_format.page_break_before = True
    for nombre in ('Source Code', 'Verbatim Char'):
        if nombre in estilos:
            estilos[nombre].font.size = Pt(8.5)  # bloques de código: caben unos 95 caracteres por línea
    for nombre in ('Image Caption', 'Caption'):
        if nombre in estilos:
            estilos[nombre].font.size = Pt(9)
            estilos[nombre].font.italic = True
            estilos[nombre].paragraph_format.alignment = WD_ALIGN_PARAGRAPH.CENTER
    doc.save(str(destino))


def generar_docx() -> None:
    from PIL import Image

    anclas_capitulo = {p.name: identificador(titulo_de(p.read_text(encoding='utf-8'))) for p in CAPITULOS}
    partes = []
    for capitulo in CAPITULOS:
        md = capitulo.read_text(encoding='utf-8')
        # Enlaces entre capítulos → enlaces internos del documento
        md = re.sub(r'\]\(([0-9][0-9]-[\w-]+\.md)#([^)]+)\)', r'](#\2)', md)
        md = re.sub(r'\]\(([0-9][0-9]-[\w-]+\.md)\)', lambda m: f'](#{anclas_capitulo[m.group(1)]})', md)
        md = re.sub(r'\[([^\]]+)\]\(\.\./(manual|tecnico)/[^)]*\)', r'\1', md)  # la otra documentación: solo el texto
        md = sin_mermaid(md)

        # Capturas altas: ancho reducido para que quepan en la página (alto máximo ≈ 8,3 pulgadas)
        def ajustar(m: re.Match) -> str:
            alto, ancho = Image.open(MANUAL / m.group(2)).size[::-1]
            pulgadas = min(6.3, ancho / 140, 8.3 * ancho / alto)
            return f'![{m.group(1)}]({m.group(2)}){{width={pulgadas:.2f}in}}'
        md = re.sub(r'!\[([^\]]*)\]\((img/[^)]+)\)', ajustar, md)
        partes.append(md)

    introduccion = (MANUAL / 'README.md').read_text(encoding='utf-8').split('\n', 1)[1]
    introduccion = re.sub(r'\[([^\]]+)\]\(([0-9][0-9]-[\w-]+\.md)\)', lambda m: f'[{m.group(1)}](#{anclas_capitulo[m.group(2)]})', introduccion)
    introduccion = introduccion.replace('(`html/index.html`)', '(carpeta `html`)')
    introduccion = re.sub(r'\[([^\]]+)\]\(\.\./(manual|tecnico)/[^)]*\)', r'\1', introduccion)
    portada = (f'---\ntitle: "{DOC["titulo"]}"\nsubtitle: "{DOC["subtitulo"]}"\n'
               f'date: "Versión de {date.today().strftime("%m-%Y")}"\nlang: es-CL\ntoc-title: "Contenido"\n---\n\n')
    texto = portada + f'# {DOC["intro"]} {{.unnumbered}}\n' + introduccion + '\n\n' + '\n\n'.join(partes)

    with tempfile.TemporaryDirectory() as tmp:
        referencia = Path(tmp) / 'referencia.docx'
        plantilla_word(referencia)
        subprocess.run(['pandoc', '-f', FORMATO, '-t', 'docx', '-o', str(DOCX), '--toc', '--toc-depth=2',
                        f'--reference-doc={referencia}', f'--resource-path={MANUAL}'],
                       input=texto, text=True, check=True, cwd=MANUAL)
    print(f'Word: {DOCX}')


if __name__ == '__main__':
    if shutil.which('pandoc') is None:
        sys.exit('Falta pandoc (sudo apt install pandoc).')
    clave = sys.argv[1] if len(sys.argv) > 1 else 'manual'
    if clave not in DOCUMENTOS:
        sys.exit(f'Uso: {sys.argv[0]} [{"|".join(DOCUMENTOS)}]')
    elegir(clave)
    (MANUAL / 'toc.tmpl').write_text('$toc$\n', encoding='utf-8')
    try:
        generar_html()
        generar_docx()
    finally:
        (MANUAL / 'toc.tmpl').unlink(missing_ok=True)
