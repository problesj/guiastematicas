#!/usr/bin/env python3
"""Genera el diagrama entidad-relación y el diccionario de datos de docs/tecnico a partir de las entidades.

Uso: python3 scripts/generar-diagrama-er.py
     (necesita PHP con las dependencias de Composer instaladas; para las imágenes PNG, Node con Playwright)

1. Lee el esquema con scripts/esquema-bd.php (metadatos de Doctrine, sin conectarse a la base de datos).
2. Dibuja los diagramas en SVG (docs/tecnico/img/er-*.svg) y, si hay Playwright, los convierte a PNG para Word.
3. Escribe docs/tecnico/04-diccionario-de-datos.md con todas las tablas y columnas, y un diagrama Mermaid
   (GitHub lo muestra como diagrama).

Después se generan el HTML y el Word con: python3 scripts/generar-manual.py tecnico
"""

from __future__ import annotations

import html
import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
TECNICO = RAIZ / 'docs' / 'tecnico'
IMG = TECNICO / 'img'

# ------------------------------------------------------------------------------------------------- modelo

TIPOS = {
    'string': 'varchar', 'text': 'text', 'json': 'json', 'integer': 'int', 'smallint': 'smallint',
    'boolean': 'bool', 'datetime_immutable': 'datetime', 'date_immutable': 'date',
}

# Descripción de las columnas cuyo nombre no lo dice todo (tabla.columna).
DESCRIPCIONES = {
    'ajuste.clave': 'Nombre del ajuste; coincide con la variable de entorno que reemplaza (p. ej. PROXY_URL)',
    'ajuste.valor': 'Valor; si «cifrado», va cifrado con sodium y una clave derivada de APP_SECRET',
    'ajuste.cifrado': 'Secreto (clave de API, contraseña): nunca se muestra en el panel',
    'autor.apellidos': 'Apellidos; un autor institucional lleva aquí el nombre completo',
    'autor.orcid': 'ORCID iD (0000-0000-0000-000X), validado',
    'bloque.tipo': 'Tipo de bloque; define la forma de «contenido»',
    'bloque.contenido': 'Datos del bloque según su tipo (ver «Contenido de los bloques»)',
    'bloque.compartible': 'Se ofrece en la biblioteca «Bloque de otra guía» para vincularlo o copiarlo',
    'bloque.origen_id': 'Si no es nulo, el bloque es un vínculo: muestra el tipo, título y contenido del original',
    'bloque.posicion': 'Orden dentro de la sección',
    'consulta.token': 'Enlace privado /consulta/{token} con que la persona sigue la conversación',
    'consulta.pendiente': 'El último mensaje es de la persona (falta respuesta)',
    'consulta.bibliotecario_id': 'Bibliotecario a quien se escribió',
    'departamento.orden': 'Posición en el directorio de bibliotecarios',
    'guia.slug': 'Dirección pública /{idioma}/guias/{slug}',
    'guia.disposicion': 'Pestañas arriba o menú lateral',
    'guia.visibilidad': 'Pública o solo para usuarios asignados (guia_lector)',
    'guia.comun': 'Guía de contenido común: no se publica; todo su contenido es reutilizable',
    'guia.traduccion_id': 'La misma guía en el otro idioma',
    'guia.principal_id': 'Guía principal de la que es subguía',
    'guia.responsable_id': 'Editor responsable: publica, cambia los datos y elimina',
    'guia.banner': 'Archivo en public/uploads/guias',
    'guia.icono': 'Archivo en public/uploads/guias',
    'materia.asjc': 'Códigos ASJC en que se clasifica una materia propia (lista JSON de enteros)',
    'materia.codigo_asjc': 'Código ASJC si la materia ES una de las 334 categorías base (no se elimina)',
    'materia.codigo': 'Código interno UCN de la carrera o asignatura',
    'mensaje_consulta.de_personal': 'Lo escribió el personal (si no, la persona que consultó)',
    'mensaje_consulta.correo_enviado': 'El aviso por correo salió bien',
    'pais.codigo': 'ISO 3166-1 alfa-2',
    'pestana.padre_id': 'Pestaña de la que es subpestaña',
    'pestana.origen_id': 'Si no es nulo, la pestaña es un vínculo y muestra las secciones del original',
    'pestana.enlace': 'Si tiene enlace, la pestaña lleva a esa dirección en vez de tener contenido',
    'pestana.nueva': 'Abre el enlace en otra ventana',
    'recurso.letra': 'Primera letra del título sin tildes (A-Z) o «#»; índice de la lista A-Z',
    'recurso.url': 'URL original, sin el prefijo del proxy',
    'recurso.requiere_proxy': 'El enlace público pasa por el proxy AccesUM',
    'recurso.prueba_hasta': 'Fin de un acceso de prueba: después deja de mostrarse',
    'recurso.fuente': 'Revista en que se publicó (artículos)',
    'recurso.clics': 'Contador de visitas por /recursos/{id}/ir',
    'recurso_autor.posicion': 'Orden del autor en la cita',
    'seccion.columnas': 'Columnas en que se reparten los bloques (1 a 3)',
    'sugerencia.identificador': 'ISBN, ISSN o DOI indicado por quien sugiere',
    'sugerencia.recurso_id': 'Recurso creado al aprobarla',
    'sugerencia.decidida_por_id': 'Quién la aprobó o rechazó',
    'usuario.password': 'Hash de la contraseña propia (nulo si entra solo por LDAP o Google)',
    'usuario.ldap_usuario': 'Usuario institucional para LDAP',
    'usuario.google_id': 'Identificador de Google, se completa en el primer acceso',
    'usuario.foto': 'Archivo en public/uploads/personal',
    'usuario.enlace_cita': 'Enlace para agendar una cita',
    'usuario.departamento_id': 'Departamento en el directorio',
}

DESCRIPCION_TABLAS = {
    'ajuste': 'Configuración editable desde el panel (Apariencia e Integraciones). Reemplaza las variables de entorno.',
    'autor': 'Autores personales o institucionales de los recursos.',
    'bloque': 'Unidad de contenido de una sección (texto, lista de recursos, libros, video…).',
    'consulta': 'Conversación iniciada desde el formulario «Escribir a…» de la ficha de un bibliotecario.',
    'departamento': 'Agrupa al personal en el directorio.',
    'editorial': 'Editoriales de libros y revistas.',
    'guia': 'Guía temática.',
    'guia_coeditor': 'Personas que editan el contenido de una guía además del responsable.',
    'guia_lector': 'Usuarios que pueden ver una guía restringida.',
    'guia_materia': 'Materias de una guía.',
    'materia': 'Materias: las 334 categorías ASJC de Scopus y las materias propias de la UCN.',
    'mensaje_consulta': 'Mensajes de una consulta.',
    'pais': 'Países ISO 3166-1.',
    'pestana': 'Pestaña (o subpestaña) de una guía.',
    'recurso': 'Lista maestra de recursos bibliográficos A-Z.',
    'recurso_autor': 'Autores de un recurso, en orden.',
    'recurso_materia': 'Materias de un recurso.',
    'seccion': 'Sección de una pestaña; contiene bloques en una o más columnas.',
    'sugerencia': 'Recurso sugerido por el público desde el formulario «Sugerir un recurso».',
    'sugerencia_materia': 'Materias propuestas para una sugerencia.',
    'usuario': 'Personal de la biblioteca y lectores de guías restringidas.',
    'usuario_materia': 'Materias de especialidad de una persona (directorio y sugerencias).',
}


class Tabla:
    def __init__(self, nombre: str, clase: str | None = None) -> None:
        self.nombre = nombre
        self.clase = clase
        self.columnas: list[dict] = []  # columna, tipo, pk, fk, nulo, unico, enum
        self.fks: list[dict] = []  # columna, destino (tabla), onDelete, nulo
        self.unicos: list[list[str]] = []
        self.indices: list[list[str]] = []

    def columna(self, nombre: str) -> dict:
        return next(c for c in self.columnas if c['columna'] == nombre)


def leer_esquema() -> dict[str, Tabla]:
    salida = subprocess.run(['php', str(RAIZ / 'scripts' / 'esquema-bd.php')], capture_output=True, text=True,
                            check=True, cwd=RAIZ).stdout
    datos = json.loads(salida)
    por_clase = {t['clase']: t['tabla'] for t in datos}
    tablas: dict[str, Tabla] = {}
    for t in datos:
        tabla = Tabla(t['tabla'], t['clase'])
        campos = [dict(c, fk=False) for c in t['campos']]
        pks = [c for c in campos if c['pk']]
        fks = []
        for r in t['rel']:
            if 'tablaIntermedia' in r:
                inter = tablas.setdefault(r['tablaIntermedia'], Tabla(r['tablaIntermedia']))
                for col, destino in ((r['columna'], t['tabla']), (r['columnaDestino'], por_clase[r['destino']])):
                    inter.columnas.append({'columna': col, 'tipo': 'integer', 'pk': True, 'fk': True, 'nulo': False,
                                           'largo': None, 'unico': False, 'enum': None})
                    inter.fks.append({'columna': col, 'destino': destino, 'onDelete': 'CASCADE', 'nulo': False})
                continue
            fks.append({'columna': r['columna'], 'tipo': 'integer', 'pk': False, 'fk': True, 'nulo': r['nulo'],
                        'largo': None, 'unico': False, 'enum': None})
            tabla.fks.append({'columna': r['columna'], 'destino': por_clase[r['destino']], 'onDelete': r['onDelete'],
                              'nulo': r['nulo']})
        tabla.columnas = pks + fks + [c for c in campos if not c['pk']]
        tabla.unicos = [u['columns'] for u in (t['unicos'] or {}).values()]
        tabla.indices = [i['columns'] for i in (t['indices'] or {}).values()]
        for u in tabla.unicos:
            if len(u) == 1:
                tabla.columna(u[0])['unico'] = True
        tablas[t['tabla']] = tabla
    return dict(sorted(tablas.items()))


def tipo_sql(c: dict) -> str:
    tipo = TIPOS.get(c['tipo'], c['tipo'])
    if tipo == 'varchar':
        tipo = f"varchar({c['largo'] or 255})"
    return tipo


# ------------------------------------------------------------------------------------------------- dibujo SVG

ANCHO = 270
FILA = 19
CABECERA = 30
COLUMNA = ANCHO + 130  # ancho de una columna de la cuadrícula (caja + pasillo para las líneas)
MARGEN = 40

COLORES = {
    'linea': '#7a6a5a', 'cabecera': '#9a602c', 'cabecera_ref': '#8c8c8c', 'cabecera_inter': '#c49a6c',
    'borde': '#c9b49c', 'fondo': '#ffffff', 'pk': '#9a602c', 'fk': '#2f6f8f', 'texto': '#1d1d1b', 'tenue': '#77706a',
}

# Cada diagrama: título y tablas con su posición (columna de la cuadrícula, y en px) y si se muestran completas.
# «compacta» muestra solo las claves (tablas de otro módulo). «salida» fuerza el lado de las líneas en casos dudosos.
DIAGRAMAS = {
    'general': {
        'titulo': 'Vista general (solo claves)',
        'tablas': {
            'departamento': (0, 0), 'ajuste': (0, 130), 'pais': (0, 560),
            'usuario': (1, 0), 'consulta': (1, 110), 'mensaje_consulta': (1, 220), 'autor': (1, 470),
            'editorial': (1, 600),
            'guia_coeditor': (2, 0), 'guia_lector': (2, 100), 'usuario_materia': (2, 200), 'sugerencia': (2, 310),
            'recurso_autor': (2, 470),
            'guia': (3, 0), 'sugerencia_materia': (3, 160), 'recurso': (3, 300),
            'pestana': (4, 0), 'seccion': (4, 130), 'bloque': (4, 230), 'guia_materia': (4, 340),
            'materia': (4, 450), 'recurso_materia': (4, 540),
        },
        'compacta': True,
    },
    'guias': {
        'titulo': 'Guías y su contenido',
        'tablas': {
            'guia_coeditor': (0, 0), 'guia_lector': (0, 110), 'guia_materia': (0, 220),
            'guia': (1, 0), 'usuario': (1, 450), 'materia': (1, 550),
            'pestana': (2, 0), 'seccion': (2, 240), 'bloque': (2, 400),
        },
        'referencia': {'usuario', 'materia'},
    },
    'recursos': {
        'titulo': 'Recursos bibliográficos y sugerencias',
        'tablas': {
            'autor': (0, 0), 'recurso_autor': (0, 170), 'editorial': (0, 290), 'pais': (0, 410),
            'recurso': (1, 0),
            'recurso_materia': (2, 0), 'materia': (2, 110), 'sugerencia_materia': (2, 200), 'sugerencia': (2, 310),
            'usuario': (2, 760),
        },
        'referencia': {'usuario', 'materia'},
    },
    'personas': {
        'titulo': 'Personas, consultas y configuración',
        'tablas': {
            'departamento': (0, 0), 'ajuste': (0, 220),
            'usuario': (1, 0),
            'usuario_materia': (2, 0), 'materia': (2, 110), 'consulta': (2, 210), 'mensaje_consulta': (2, 460),
        },
        'referencia': {'materia'},
    },
}


def cruces(puntos: list, cajas: dict, propias: tuple) -> int:
    """Cajas que atraviesan los tramos horizontales de una línea (los verticales van por los pasillos)."""
    total = 0
    for (xa, ya), (xb, yb) in zip(puntos, puntos[1:]):
        if ya != yb:
            continue
        izq, der = sorted((xa, xb))
        for caja in cajas.values():
            if any(caja is p for p in propias):
                continue
            if caja['y'] - 4 <= ya <= caja['y'] + caja['alto'] + 4 and izq < caja['x'] + ANCHO and der > caja['x']:
                total += 1
    return total


def columnas_visibles(tabla: Tabla, compacta: bool) -> list[dict]:
    if not compacta:
        return tabla.columnas
    return [c for c in tabla.columnas if c['pk'] or c['fk']] or tabla.columnas[:1]


def svg_diagrama(tablas: dict[str, Tabla], clave: str) -> str:
    conf = DIAGRAMAS[clave]
    compacta_todo = conf.get('compacta', False)
    referencia = conf.get('referencia', set())
    cajas = {}
    for nombre, (col, y) in conf['tablas'].items():
        tabla = tablas[nombre]
        compacta = compacta_todo or nombre in referencia
        filas = columnas_visibles(tabla, compacta)
        x = MARGEN + col * COLUMNA
        y = MARGEN + 40 + y
        cajas[nombre] = {'x': x, 'y': y, 'alto': CABECERA + len(filas) * FILA + 6, 'filas': [c['columna'] for c in filas],
                         'tabla': tabla, 'compacta': compacta, 'ref': nombre in referencia}

    ancho = max(c['x'] + ANCHO for c in cajas.values()) + MARGEN + 60
    alto = max(c['y'] + c['alto'] for c in cajas.values()) + MARGEN + 50

    def fila_y(caja: dict, columna: str) -> float:
        i = caja['filas'].index(columna) if columna in caja['filas'] else 0
        return caja['y'] + CABECERA + i * FILA + FILA / 2 + 1

    lineas = []
    pasillos: dict[tuple[float, str], int] = {}
    for nombre, caja in cajas.items():
        for fk in caja['tabla'].fks:
            if fk['destino'] not in cajas:
                continue
            destino = cajas[fk['destino']]
            y1 = fila_y(caja, fk['columna'])
            y2 = fila_y(destino, 'id')
            uno_opcional = fk['nulo']
            if destino is caja or abs(destino['x'] - caja['x']) < 1:
                # Misma columna (o autorreferencia): bucle por la derecha
                xd = caja['x'] + ANCHO
                n = pasillos.setdefault((xd, 'bucle'), 0)
                pasillos[(xd, 'bucle')] += 1
                xm = xd + 16 + n * 8
                puntos = [(xd, y1), (xm, y1), (xm, y2), (xd, y2)]
                lado_hijo, lado_padre = 1, 1
            else:
                hacia_derecha = destino['x'] > caja['x']
                x1 = caja['x'] + ANCHO if hacia_derecha else caja['x']
                x2 = destino['x'] if hacia_derecha else destino['x'] + ANCHO
                # El codo va en un pasillo (sin cajas): junto al destino o junto al origen, el que cruce menos cajas.
                opciones = []
                for junto, x_ref in (('destino', x2), ('origen', x1)):
                    hacia_ref = 1 if (junto == 'destino') == hacia_derecha else -1  # el codo queda antes de x_ref
                    base = x_ref - (40 if hacia_derecha else 66) * hacia_ref if junto == 'destino' else x_ref + 60 * (1 if hacia_derecha else -1)
                    clave = (round(base), junto)
                    n = pasillos.get(clave, 0)
                    xm = base - 8 * n * (1 if hacia_derecha else -1)
                    pts = [(x1, y1), (xm, y1), (xm, y2), (x2, y2)]
                    opciones.append((cruces(pts, cajas, (caja, destino)), len(opciones), clave, pts))
                _, _, clave, puntos = min(opciones)
                pasillos[clave] = pasillos.get(clave, 0) + 1
                lado_hijo, lado_padre = (1, -1) if hacia_derecha else (-1, 1)
            lineas.append((puntos, lado_hijo, lado_padre, uno_opcional))

    s = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{ancho}" height="{alto}" viewBox="0 0 {ancho} {alto}" '
         f'font-family="DejaVu Sans, Segoe UI, Arial, sans-serif" font-size="12">',
         f'<rect width="100%" height="100%" fill="{COLORES["fondo"]}"/>',
         f'<text x="{MARGEN}" y="{MARGEN + 8}" font-size="20" font-weight="700" fill="{COLORES["cabecera"]}">'
         f'{html.escape(conf["titulo"])}</text>']

    def marca_muchos(x: float, y: float, lado: int) -> str:
        # Pata de gallo (muchos, obligatorio): tres líneas que se abren hacia la caja y una barra.
        a = x + lado * 14
        return (f'<path d="M{a} {y} L{x} {y - 7} M{a} {y} L{x} {y} M{a} {y} L{x} {y + 7}" stroke="{COLORES["linea"]}" '
                f'stroke-width="1.4" fill="none"/>'
                f'<path d="M{x + lado * 19} {y - 7} V{y + 7}" stroke="{COLORES["linea"]}" stroke-width="1.4"/>')

    def marca_uno(x: float, y: float, lado: int) -> str:
        # Una barra: «uno». Si la clave foránea admite nulo se ve en la columna («?»): así varias líneas pueden
        # llegar al mismo punto sin mezclar marcas.
        return (f'<path d="M{x + lado * 10} {y - 7} V{y + 7}" stroke="{COLORES["linea"]}" stroke-width="1.4"/>'
                f'<path d="M{x + lado * 15} {y - 7} V{y + 7}" stroke="{COLORES["linea"]}" stroke-width="1.4"/>')

    for puntos, lado_hijo, lado_padre, opcional in lineas:
        d = 'M' + ' L'.join(f'{px:.1f} {py:.1f}' for px, py in puntos)
        s.append(f'<path d="{d}" stroke="{COLORES["linea"]}" stroke-width="1.4" fill="none"/>')
        s.append(marca_muchos(puntos[0][0], puntos[0][1], lado_hijo))
        s.append(marca_uno(puntos[-1][0], puntos[-1][1], lado_padre))

    for nombre, caja in cajas.items():
        x, y, tabla = caja['x'], caja['y'], caja['tabla']
        intermedia = tabla.clase is None
        color = COLORES['cabecera_ref'] if caja['ref'] else COLORES['cabecera_inter'] if intermedia else COLORES['cabecera']
        s.append(f'<g><rect x="{x}" y="{y}" width="{ANCHO}" height="{caja["alto"]}" rx="6" fill="#fff" '
                 f'stroke="{COLORES["borde"]}" stroke-width="1.2"/>')
        s.append(f'<path d="M{x} {y + 6} a6 6 0 0 1 6 -6 h{ANCHO - 12} a6 6 0 0 1 6 6 v{CABECERA - 6} h{-ANCHO} z" '
                 f'fill="{color}"/>')
        s.append(f'<text x="{x + 10}" y="{y + 20}" fill="#fff" font-weight="700" font-size="13">{nombre}</text>')
        if tabla.clase:
            s.append(f'<text x="{x + ANCHO - 10}" y="{y + 20}" fill="#fff" font-size="11" text-anchor="end" '
                     f'opacity=".85">{tabla.clase}</text>')
        for i, columna in enumerate(caja['filas']):
            c = tabla.columna(columna)
            cy = y + CABECERA + i * FILA + 14
            if i % 2:
                s.append(f'<rect x="{x + 1}" y="{cy - 13}" width="{ANCHO - 2}" height="{FILA}" fill="#faf6f1"/>')
            marca = 'PK' if c['pk'] and not c['fk'] else 'PK FK' if c['pk'] else 'FK' if c['fk'] else 'UQ' if c['unico'] else ''
            color_marca = COLORES['pk'] if c['pk'] else COLORES['fk'] if c['fk'] else COLORES['tenue']
            peso = ' font-weight="700"' if c['pk'] else ''
            s.append(f'<text x="{x + 10}" y="{cy}" fill="{COLORES["texto"]}"{peso}>{columna}'
                     f'{"" if not c["nulo"] else "<tspan fill=\"" + COLORES["tenue"] + "\">?</tspan>"}</text>')
            s.append(f'<text x="{x + ANCHO - 46}" y="{cy}" fill="{COLORES["tenue"]}" font-size="10.5" '
                     f'text-anchor="end">{html.escape(tipo_sql(c))}</text>')
            if marca:
                s.append(f'<text x="{x + ANCHO - 8}" y="{cy}" fill="{color_marca}" font-size="10" font-weight="700" '
                         f'text-anchor="end">{marca}</text>')
        s.append('</g>')

    # Leyenda
    leyenda = ['PK clave primaria · FK clave foránea · UQ única · «?» admite nulo (relación opcional)',
               'Pata de gallo = muchos · doble barra = uno · cabecera gris = tabla de otro diagrama (solo claves) · '
               'cabecera clara = tabla intermedia (muchos a muchos)']
    for i, texto in enumerate(leyenda):
        s.append(f'<text x="{MARGEN}" y="{alto - 34 + i * 16}" fill="{COLORES["tenue"]}" font-size="11.5">{html.escape(texto)}</text>')
    s.append('</svg>')
    return '\n'.join(s)


def a_png(svgs: list[Path]) -> None:
    if shutil.which('node') is None:
        print('Sin Node: no se generan los PNG.', file=sys.stderr)
        return
    script = """
const { chromium } = require('playwright');
(async () => {
  const navegador = await chromium.launch();
  const pagina = await navegador.newPage({ deviceScaleFactor: 2 });
  for (const ruta of process.argv.slice(2)) {
    await pagina.goto('file://' + ruta);
    const svg = await pagina.$('svg');
    await svg.screenshot({ path: ruta.replace(/\\.svg$/, '.png') });
  }
  await navegador.close();
})();
"""
    with tempfile.TemporaryDirectory() as tmp:
        archivo = Path(tmp) / 'png.js'
        archivo.write_text(script, encoding='utf-8')
        subprocess.run(['node', str(archivo), *map(str, svgs)], check=True)


# ------------------------------------------------------------------------------------------------- Markdown

def mermaid(tablas: dict[str, Tabla]) -> str:
    lineas = ['```mermaid', 'erDiagram']
    for t in tablas.values():
        for fk in t.fks:
            izquierda = '|o' if fk['nulo'] else '||'
            lineas.append(f'    {fk["destino"]} {izquierda}--o{{ {t.nombre} : "{fk["columna"]}"')
    for t in tablas.values():
        lineas.append(f'    {t.nombre} {{')
        for c in t.columnas:
            marcas = ','.join(m for m, si in (('PK', c['pk']), ('FK', c['fk']), ('UK', c['unico'] and not c['pk'])) if si)
            tipo = TIPOS.get(c['tipo'], c['tipo'])
            lineas.append(f'        {tipo} {c["columna"]}{" " + marcas if marcas else ""}')
        lineas.append('    }')
    lineas.append('```')
    return '\n'.join(lineas)


def diccionario(tablas: dict[str, Tabla]) -> str:
    partes = [
        '# 4. Diccionario de datos',
        '',
        '> Este capítulo se genera con `scripts/generar-diagrama-er.py` a partir de las entidades de Doctrine '
        '(`src/Entity`). No lo edites a mano: cambia la entidad, crea la migración y vuelve a generarlo.',
        '',
        f'La base de datos tiene **{len(tablas)} tablas** (más `doctrine_migration_versions`, que lleva el registro de '
        'las migraciones aplicadas). Todas usan InnoDB con `utf8mb4` y `utf8mb4_unicode_ci`.',
        '',
        '**Convenciones.** Claves primarias `id` autoincrementales (salvo `ajuste`, cuya clave es `clave`, y las '
        'tablas intermedias, con clave compuesta). Las columnas `*_id` son claves foráneas. Las fechas se guardan '
        'como `DATETIME` en la hora del servidor. Los valores de las enumeraciones (`App\\Enum`) se guardan como texto.',
        '',
        '| Tabla | Entidad | Para qué sirve |',
        '| ------------ | ------------ | ---------------------------- |',
    ]
    for t in tablas.values():
        partes.append(f'| [`{t.nombre}`](#{t.nombre.replace("_", "_")}) | {t.clase or "(intermedia)"} | '
                      f'{DESCRIPCION_TABLAS.get(t.nombre, "")} |')
    for t in tablas.values():
        partes += ['', f'## {t.nombre}', '']
        partes.append(f'{DESCRIPCION_TABLAS.get(t.nombre, "")}' + (f' Entidad `App\\Entity\\{t.clase}`.' if t.clase else ''))
        partes += ['', '| Columna | Tipo | Nulo | Clave | Descripción |',
                   '| ------------ | ---------- | :--: | :----: | ------------------------------ |']
        for c in t.columnas:
            clave = ' '.join(m for m, si in (('PK', c['pk']), ('FK', c['fk']), ('UQ', c['unico'] and not c['pk'])) if si)
            desc = DESCRIPCIONES.get(f'{t.nombre}.{c["columna"]}', '')
            fk = next((f for f in t.fks if f['columna'] == c['columna']), None)
            if fk:
                accion = {'CASCADE': 'se borra en cascada', 'SET NULL': 'queda en nulo'}.get(fk['onDelete'] or '', 'restringido')
                desc = (desc + '. ' if desc else '') + f'→ `{fk["destino"]}.id` (al borrar el destino: {accion})'
            if c['enum']:
                desc = (desc + '. ' if desc else '') + 'Valores: ' + ', '.join(f'`{v}`' for v in c['enum'])
            partes.append(f'| `{c["columna"]}` | {tipo_sql(c)} | {"sí" if c["nulo"] else ""} | {clave} | {desc} |')
        extras = [f'única ({", ".join(u)})' for u in t.unicos if len(u) > 1] + [f'índice ({", ".join(i)})' for i in t.indices]
        if extras:
            partes += ['', 'Índices adicionales: ' + '; '.join(extras) + '.']
    partes += ['', '## Diagrama completo en Mermaid', '',
               'GitHub dibuja este bloque como diagrama. También sirve para pegarlo en https://mermaid.live o en '
               'cualquier herramienta compatible.', '', mermaid(tablas), '']
    return '\n'.join(partes)


if __name__ == '__main__':
    tablas = leer_esquema()
    IMG.mkdir(parents=True, exist_ok=True)
    svgs = []
    for clave in DIAGRAMAS:
        ruta = IMG / f'er-{clave}.svg'
        ruta.write_text(svg_diagrama(tablas, clave), encoding='utf-8')
        svgs.append(ruta)
    a_png(svgs)
    (TECNICO / '04-diccionario-de-datos.md').write_text(diccionario(tablas), encoding='utf-8')
    print(f'{len(tablas)} tablas; diagramas en {IMG}')
