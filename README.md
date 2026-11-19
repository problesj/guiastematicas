# Guías temáticas · Bibliotecas UCN

Plataforma para gestionar guías temáticas de Bibliotecas UCN, inspirada en las funcionalidades de
[SubjectsPlus 5](https://subjectsplus.github.io/sp5-new-release.html). Las decisiones y las fases están en [docs/PLAN.md](docs/PLAN.md).

**Stack:** Symfony 7.4 LTS, PHP 8.5, MySQL 8.4, Apache 2.4 sobre Ubuntu 26.04 LTS.

## Desarrollo local con Docker

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app chown -R www-data:www-data var
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

- Sitio: http://localhost:8080 (redirige a `/es` o `/en` según el navegador)
- Chequeo de salud: http://localhost:8080/salud
- MySQL: `127.0.0.1:3306`, base `guias`, usuario `guias`, contraseña `guias`
- Datos de ejemplo (solo desarrollo): `docker compose exec app php bin/console doctrine:fixtures:load`.
  Crea departamentos, materias y cinco cuentas (`admin@ucn.cl`, `editora1@ucn.cl`, ...) con la contraseña `guias-desarrollo`.

## Pruebas

```bash
docker compose exec app php bin/phpunit
```

La integración continua (GitHub Actions) ejecuta en cada pull request las revisiones de sintaxis,
las migraciones contra MySQL 8.4, PHPUnit con PHP 8.5, `composer audit` y la construcción de la imagen Docker.

## Servidor (Ubuntu 26.04 LTS)

Sirve igual para la máquina virtual de pruebas y para el servidor de producción de la UCN.

**Instalación en un solo paso** (servidor piloto u otro servidor limpio): `scripts/montar-piloto.sh` hace todos los
pasos de abajo, genera las contraseñas, crea el primer administrador y programa el respaldo diario. Las credenciales
quedan en `/root/guias-credenciales.txt`.

```
curl -fsSL https://raw.githubusercontent.com/problesj/guiastematicas/piloto-2026-11-10/scripts/montar-piloto.sh -o montar-piloto.sh
sudo ADMIN_EMAIL=correo@ucn.cl bash montar-piloto.sh
```

Opcional: `RESTAURAR_BD=bd_....sql.gz RESTAURAR_ARCHIVOS=archivos_....tar.gz` para partir con los datos de otro servidor
(respaldos de `scripts/respaldar.sh`), `VERSION=main` para la última versión y `SERVIDOR=<nombre o IP>`.

**Pasar los datos de un servidor a otro** (por ejemplo, de pruebas al piloto) en una instalación que ya funciona: en el
origen `sudo bash /var/www/guias/scripts/respaldar.sh`, copiar los dos archivos de `/var/backups/guias` al destino y ahí
`sudo bash /var/www/guias/scripts/restaurar.sh bd_....sql.gz archivos_....tar.gz`. Respalda antes los datos del destino;
las cuentas y contraseñas pasan con la base de datos y las claves cifradas del panel de integraciones se borran (el
destino tiene otro `APP_SECRET`).

Paso a paso:

1. Instalar paquetes, Apache con PHP-FPM, MySQL y cortafuegos:
   `sudo DB_PASSWORD='...' bash scripts/instalar-servidor.sh`
2. Clonar el repositorio en `/var/www/guias` y crear `/var/www/guias/.env.local`:
   ```
   APP_ENV=prod
   APP_SECRET=<cadena aleatoria de 32 caracteres o más>
   DATABASE_URL="mysql://guias:<contraseña>@127.0.0.1:3306/guias?serverVersion=8.4.11&charset=utf8mb4"
   ```
   Permisos: `chmod 640 .env.local`.
3. Desplegar: `sudo bash scripts/desplegar.sh main`
4. Solo en producción, con el dominio apuntando al servidor: `sudo certbot --apache -d <dominio>`
5. Respaldos diarios: agregar `scripts/respaldar.sh` a cron (ver el encabezado del script).

6. Crear el primer administrador (pide la contraseña de forma oculta):
   `sudo -u www-data php bin/console app:crear-administrador correo@ucn.cl "Nombre" "Apellidos"`

El VirtualHost de Apache está en [deploy/apache/guias.conf](deploy/apache/guias.conf).

## Integraciones desde el panel

Un administrador puede definir en **Administración → Integraciones** (`/es/admin/configuracion`) estos grupos:

| Grupo | Variables | Para qué |
| --- | --- | --- |
| Proxy | `PROXY_URL` | Enlaces de los recursos suscritos (AccesUM) |
| Catálogo | `PRIMO_URL`, `PRIMO_VID`, `EXLIBRIS_API_URL`, `PRIMO_API_KEY`, `ALMA_API_KEY` | Búsqueda, datos de libros y revistas, disponibilidad |
| Biblioges | `BIBLIOGES_API_URL`, `BIBLIOGES_API_KEY` | Bibliografías por carrera o asignatura |
| LDAP | `LDAP_URL`, `LDAP_CIFRADO`, `LDAP_DN_USUARIO`, `LDAP_BASE_DN`, `LDAP_FILTRO`, `LDAP_BIND_DN`, `LDAP_BIND_PASSWORD` | Inicio de sesión institucional |
| Google | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_DOMINIO` | Inicio de sesión con Google |
| Correo (SMTP) | `CORREO_SMTP_HOST`, `_PUERTO`, `_CIFRADO`, `_USUARIO`, `_CLAVE`, `CORREO_REMITENTE`, `CORREO_REMITENTE_NOMBRE` | Avisos de consultas a bibliotecarios; botón «Enviar correo de prueba». `CORREO_DSN` en `.env.local` reemplaza a todo el grupo |
| Inteligencia artificial (Gemini) | `GEMINI_API_KEY`, `GEMINI_MODELO` (por omisión `gemini-2.5-flash`) | Asistente «Nueva guía con IA» |
| Moodle (LTI 1.3) | `LTI_ISSUER`, `LTI_CLIENT_ID`, `LTI_DEPLOYMENT_ID`, `LTI_AUTH_URL`, `LTI_JWKS_URL` | Herramienta externa en Moodle (ver abajo) |

En la misma página se descarga el **plugin de WordPress** ya configurado con la dirección del servidor. Lo definido en el panel reemplaza a la variable de entorno del mismo nombre; lo que queda
vacío usa el `.env.local` del servidor. Las claves se guardan cifradas (sodium) con una llave derivada de `APP_SECRET`
y nunca se vuelven a mostrar: si `APP_SECRET` cambia, hay que escribirlas de nuevo. Los servicios leen estos valores
con `%env(ajuste:NOMBRE)%` (`App\Configuracion\AjusteEnvVarProcessor`).

## Apariencia y portada

En **Administración → Apariencia** se definen el nombre del sitio (español e inglés), la institución, el logo, la imagen
de fondo de la portada y los colores: principal, barra y pie, y el color del texto por zona (general, títulos, cabecera,
barra, botones y pie). Cada par de colores se valida con contraste AA antes de guardar. Ahí también se elige cómo se
presentan **Guías por materia** (lista, cuadrícula o carrusel; ajuste `VISTA_GUIAS`) y los **accesos a recursos**
Bases de datos, Libros y Revistas (deshabilitado, lista o cuadrícula; `VISTA_BASE_DATOS`, `VISTA_LIBROS`,
`VISTA_REVISTAS`). Cada acceso habilitado aparece en el menú principal con su página
`/es/recursos-bibliograficos/{bases-de-datos|libros|revistas}`; uno deshabilitado responde 404.

La portada muestra la imagen de fondo con un buscador (catálogo o sitio, este último en `/es/buscar`), accesos rápidos,
tarjetas de módulos y un carrusel con los 10 recursos agregados más recientes. Los libros y revistas sin portada se
muestran con el icono de su tipo.

## Accesibilidad (WCAG 2.1 AA)

Revisión automática con axe-core 4.10 (reglas wcag2a, wcag2aa, wcag21a, wcag21aa y buenas prácticas) sobre 24 páginas
públicas y del panel, en escritorio (1280 px) y celular (390 px): sin errores y sin desplazamiento horizontal
(12-11-2026). El color principal del tema es el 471 C oscurecido (#9a602c) para llegar a 4,5:1; los colores propios
que se definan en Apariencia deben cumplir el mismo contraste. La revisión automática no reemplaza una prueba con
lector de pantalla y teclado.

## Inicio de sesión del personal

El personal entra en `/es/acceso` (o `/en/acceso`). Solo pueden entrar personas creadas antes por un administrador
en **Panel → Usuarios**; nadie se registra solo. Cada persona puede tener uno o más métodos:

| Método | Cómo se activa | Variables en `.env.local` |
| --- | --- | --- |
| Contraseña propia | El administrador le asigna una contraseña (mínimo 12 caracteres) | ninguna |
| LDAP UCN | El administrador anota su usuario LDAP; entra con ese usuario y la contraseña institucional | `LDAP_URL`, `LDAP_CIFRADO` y `LDAP_DN_USUARIO`, o bien `LDAP_BASE_DN`, `LDAP_FILTRO`, `LDAP_BIND_DN`, `LDAP_BIND_PASSWORD` |
| Google | El correo de su cuenta de Google coincide con el registrado | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` y, opcional, `GOOGLE_DOMINIO=ucn.cl` |

Mientras `LDAP_URL` o `GOOGLE_CLIENT_ID` estén vacías, ese método queda apagado y no aparece en el formulario.
Para Google, crear un "ID de cliente OAuth" de tipo aplicación web en Google Cloud Console con la URI de redirección
`https://<dominio>/conectar/google/verificar`. El ejemplo de cada variable está en [.env](.env).

Roles: **Administrador** (usuarios, departamentos, materias y todo el sitio), **Editor** (crea y publica sus guías)
**Colaborador** (edita las guías que le compartan como coeditor) y **Lector** (solo inicia sesión para ver
las guías restringidas que tenga asignadas; no entra al panel ni aparece en el directorio de bibliotecarios).

Cada guía puede ser **pública** o de **usuarios asignados** (en **Datos de la guía → Visibilidad**). Una guía
restringida no aparece en la lista pública ni en buscadores; al abrirla se pide iniciar sesión y solo la ven las
personas asignadas, el responsable, los coeditores y los administradores. Las imágenes subidas a la guía siguen
siendo accesibles por su dirección directa.

## Recursos bibliográficos A-Z y proxy AccesUM

La lista pública está en `/es/recursos-bibliograficos` (la dirección antigua `/es/bases-de-datos` redirige).
Editores y administradores la gestionan en **Panel → Recursos bibliográficos**,
uno a uno o importando el Excel (**Importar desde Excel**). La importación muestra una vista previa antes de guardar,
reconoce los encabezados en español o inglés y ofrece una plantilla descargable. Un recurso que ya existe (misma URL o
mismo título) se actualiza en vez de duplicarse.

Cada recurso guarda su URL original y la marca "requiere proxy". El enlace público se arma con `PROXY_URL` (en `.env.local`):

- prefijo: `PROXY_URL=https://accesum.ucn.cl/login?url=` (se agrega la URL original), o
- plantilla: `PROXY_URL=https://proxy.ucn.cl/login?qurl={url}` (se reemplaza `{url}` por la URL codificada).

Si el Excel trae las URL con el prefijo del proxy, la importación lo quita y marca "requiere proxy".
Los clics de salida (`/recursos/{id}/ir`) se cuentan por recurso.

Cada tipo de recurso tiene sus propios datos bibliográficos (definidos en `TipoRecurso::campos()`):

| Tipo | Datos |
| --- | --- |
| Libros | Autores, editorial, año de edición, ISBN, e-ISBN, DOI |
| Revistas | Editorial, ISSN, e-ISSN, disponible desde |
| Artículo científico | Autores, fuente (revista), año, volumen, fascículo, páginas, DOI |
| Base de datos | Disponible desde |
| Normas | Código de la norma, organismo emisor, año |
| Multimedia | Autores, año |

El formulario muestra solo los del tipo elegido, valida ISBN, ISSN y DOI, y al guardar vacía los que no corresponden.
Un artículo sin URL usa el enlace de su DOI (`https://doi.org/...`). Un recurso multimedia con enlace de YouTube o Vimeo
muestra el reproductor en la lista pública (YouTube en modo de privacidad mejorada).

Autores y editoriales tienen tablas propias, administradas en **Panel → Autores** y **Panel → Editoriales**:

- **Autor**: nombres, apellidos, ORCID iD (se valida el dígito verificador), sexo y país. Un autor institucional
  (p. ej. "Organización Mundial de la Salud") lleva el nombre completo en apellidos.
- **Editorial**: nombre (único) y país.
- **País**: tabla ISO 3166-1 con nombre en español e inglés, precargada por la migración y editable en
  **Administración → Países** (el botón *Completar lista ISO* repone los que falten).

En el formulario de un recurso, el selector de autores busca entre los existentes o agrega uno nuevo escrito como
"Apellidos, Nombres", y permite ordenarlos como en la cita (`recurso_autor.posicion`). La editorial se elige de la lista
o se escribe una nueva. Al importar, cada autor ("Apellidos, Nombres", separados por punto y coma) y cada editorial se
buscan sin distinguir mayúsculas ni tildes y se crean solo si no existen (`App\Servicio\RegistroBibliografico`).

Además de Excel, la importación acepta archivos **BibTeX (.bib)** y **RIS (.ris)** exportados desde Zotero, Mendeley,
EndNote, Google Académico, Scopus o Web of Science (`App\Servicio\Importacion\LectorBibliografia`).

Cada libro y revista tiene un **formato**: impreso, electrónico o ambos (por omisión electrónico). Se muestra como
insignia con icono en todas las vistas y se puede filtrar en la lista A-Z, en el panel de recursos y en el buscador del
bloque Recursos (los filtros impreso y electrónico incluyen «ambos»). La importación acepta la columna Formato.

Cada recurso de la lista A-Z tiene botones para compartirlo (Facebook, X, LinkedIn, WhatsApp, correo y copiar enlace);
lo compartido es `/recursos/{id}/ir`.

### Sugerencias de recursos

Cualquier persona puede sugerir un recurso desde `/es/recursos-bibliograficos/sugerir`, sin cuenta, con su nombre y
correo. El formulario usa un captcha propio de prueba de trabajo (sin servicios de Google) y acepta 5 sugerencias por
hora por dirección IP. Las revisan en **Panel → Sugerencias** quienes tienen la materia en su perfil y los responsables
y coeditores de guías con esa materia; el administrador ve todas. El panel muestra cuántas hay pendientes. Al aprobar,
el recurso entra a la lista A-Z.

## Materias y categorías ASJC (Scopus)

Las materias son propias de la UCN (Administración → Materias). Cada una puede asociarse a una o más categorías
**ASJC** de Scopus (334 categorías de 4 dígitos en 27 campos; lista oficial de Elsevier, nombres en español
traducidos por nosotros). El formulario de la materia agrupa las categorías por campo y tiene un buscador por
nombre o código.

Con las categorías, la plataforma calcula la **gran área** de cada materia (Ciencias de la salud, de la vida,
físicas, sociales y humanidades, multidisciplinaria). En «Guías por materia» y en la lista A-Z de recursos aparece
el filtro «Gran área» (parámetro `area`), solo con las áreas que tienen materias. Las materias sin categorías
siguen funcionando igual; solo no aparecen al filtrar por área.

## Directorio y consultas a bibliotecarios

La ficha pública de cada bibliotecario tiene el formulario «Escribir a…» (captcha propio, 10 mensajes por hora por IP;
el correo del directorio lleva a ese formulario). Cada consulta queda registrada: la persona recibe una copia con un
enlace privado `/es/consulta/{token}` para seguir la conversación y responder, y el bibliotecario la ve y responde en
**Panel → Consultas** (puede cerrarla y reabrirla; el administrador ve todas). Los correos salen solo si está
configurado el grupo «Correo (SMTP)»; sin él, las consultas se registran igual. La tarjeta y la ficha muestran además
de qué guías publicadas y públicas es editor o colaborador.

## Guías temáticas (editor)

Las guías se crean en **Panel → Guías temáticas** y se ven en `/es/guias` (lista por materia) y `/es/guias/{slug}`.
Cada guía tiene pestañas, cada pestaña secciones y cada sección bloques. Pestañas, secciones y bloques se ordenan
arrastrando el ícono ⠿ (SortableJS); un bloque puede pasar a otra sección. La guía se muestra con pestañas arriba o
con menú lateral, según se elija en sus datos.

El esquema sigue el de LibGuides: cada bloque se muestra como una caja con barra de título y cada sección reparte sus
cajas en 1, 2 o 3 columnas (una sola en celulares). En **Opciones de la pestaña** una pestaña puede ser subpestaña de
otra (un nivel; se despliega bajo su pestaña o va sangrada en el menú lateral), ser solo un enlace externo (se abre en
otra ventana) o llevar la etiqueta «Nuevo». La guía publicada muestra la ruta Bibliotecas UCN › Guías por materia ›
materia › guía › pestaña, botones Anterior / Siguiente en el orden de lectura (sin los enlaces externos) y un pie con
la última actualización, la dirección de la página, las materias, Imprimir (hoja de estilos de impresión) y Reportar un
problema (correo al responsable).

| Bloque | Contenido |
| --- | --- |
| Texto | Editor CKEditor 5; el HTML se sanea en el servidor (`config/packages/html_sanitizer.yaml`) |
| Recursos | Recursos bibliográficos elegidos de la lista A-Z, en orden manual o alfabético (A–Z), con o sin descripción. En la guía, con más de 20 recursos de dos o más tipos se ofrecen botones para filtrar por tipo |
| Perfil | Ficha de un bibliotecario del directorio |
| Video | YouTube o Vimeo |
| Búsqueda en el catálogo | Caja de búsqueda que abre Primo VE (`PRIMO_URL` y `PRIMO_VID` en `.env.local`) |
| Lista de enlaces | Sitios web externos con descripción opcional. Cada enlace puede marcarse «Agregar a recursos bibliográficos»: al guardar se crea (o se vincula, si la dirección ya existe) un recurso de tipo Sitio web, de acceso abierto, sin proxy y con las materias de la guía; desmarcarlo solo desvincula |
| Libros | Lista por ISBN o DOI: el botón «Buscar datos» (y el guardado) completa título, autor, editorial y año desde el catálogo (ver abajo) o desde doi.org; portada de Open Library y enlace al registro en Primo VE o al DOI. En el editor es una lista paginada (5 a 20 por página) con Ver, Editar y Borrar, en orden manual o por título. En la guía se muestra en cuadrícula (con columnas a elección), carrusel o lista breve paginada |
| Revistas | Igual que Libros, a mano, por ISSN o desde la búsqueda de revistas de Primo (por título o por categoría: área, subárea y especialidad del árbol de Primo; también acepta pegar un enlace de esa búsqueda). Se incorporan a la lista A-Z como tipo Revistas |
| Diapositivas | Carrusel de imágenes subidas (`public/uploads/guias/d{id}-*`), cada una con texto y enlace opcionales; las que dejan de usarse se borran |

En la guía, los bloques Libros y Revistas con más de 20 elementos traen el campo «Buscar en esta lista» (título, autor,
editorial, año o ISBN; en revistas, título, editorial o ISSN; sin distinguir mayúsculas ni tildes). Funciona con la
lista paginada, la cuadrícula y el carrusel.

Los recursos de acceso abierto llevan la marca «Recurso libre», y el perfil muestra la ubicación (oficina) y, si se
indicó en la ficha de la persona, un botón «Agenda una cita».

Cada guía puede llevar una imagen de cabecera (banner, 4:1) y un ícono cuadrado, que se guardan en `public/uploads/guias` (JPG, PNG o WebP; sin SVG).

Permisos: un **Editor** crea guías y es su responsable (edita datos, publica y elimina); los **coeditores** que elija
editan el contenido. El **Administrador** edita todas y puede cambiar el responsable. Una guía nace en borrador y solo se
ve en la vista previa del panel hasta que se publica.

**Guías principales y subguías**: en los datos de una guía se puede elegir su «Guía principal». La principal lista sus
subguías en la portada, y la subguía muestra «Parte de» y la ruta completa en las migas (se impiden los ciclos).

**Mover bloques**: el botón «Mover» de cada bloque lo lleva a otra sección, pestaña o guía.

**Compartir la guía**: la guía publicada tiene botones para Facebook, X, LinkedIn, WhatsApp, correo y copiar enlace.

### Contenido compartido entre guías

Un bloque o una pestaña puede marcarse **compartible**. Desde «Bloque de otra guía» o «Pestaña de otra guía» se elige y
se agrega como **vínculo** (muestra siempre el contenido actual del original y se edita solo en el original) o como
**copia** independiente. Un vínculo puede convertirse después en copia propia («Convertir en copia propia»). Al
eliminar un original con vínculos se avisa qué guías lo usan, y los vínculos se borran con él.

El administrador puede marcar una guía como de **contenido común**: no se publica y todos sus bloques y pestañas son
reutilizables (por ejemplo, bases multidisciplinarias o normas de citación).

### Asistente «Nueva guía con IA»

En **Panel → Guías temáticas**, junto a «Nueva guía (manual)», está **Nueva guía con IA**. Se indican título, materias
(al menos una), coeditores y hasta 5 carreras de Biblioges, y el asistente arma una guía en borrador con estas pestañas:

1. **Presentación**: bienvenida y fichas del responsable, coeditores y colaboradores.
2. **Bases de datos**: introducción, recursos de las materias y un vínculo al bloque transversal de bases multidisciplinarias.
3. **Revistas** y 4. **Sitios web**: recursos de las materias; en Sitios web, además, un bloque «Sugeridos por IA
   (revisar)» con sitios que propone Gemini, solo los que responden por https (no entran a la lista A-Z).
5. **Citación y gestores**: vínculo a la pestaña transversal.
6. **Libros**: libros de las materias y la bibliografía básica disponible de las carreras en Biblioges (última edición),
   que se incorporan a la lista A-Z.

Con Gemini, la IA elige los recursos más pertinentes y escribe los textos (se guardan como texto, sin HTML). Sin clave,
o si Gemini no responde, la guía se arma igual con todos los recursos de las materias en orden alfabético y se avisa. El
bloque y la pestaña transversales los elige el administrador al final de la página del asistente (ajustes
`IA_BLOQUE_MULTIDISCIPLINARIAS` e `IA_PESTANA_CITACION`). A Gemini solo se envían materias, carreras y títulos y
descripciones de recursos.

Idiomas: cada guía está en un idioma (español o inglés). La versión en el otro idioma es otra guía enlazada en
**Datos de la guía → Traducción**; el selector ES/EN del sitio lleva de una a otra.

### Catálogo (Primo VE y Alma)

Con `EXLIBRIS_API_URL`, `PRIMO_API_KEY`, `PRIMO_URL` y `PRIMO_VID` en `.env.local`, el bloque Libros completa cada
libro desde la API de búsqueda de Primo VE con solo el ISBN (también se pueden pegar varios ISBN de una vez) y lo enlaza
a su registro (`/discovery/fulldisplay`). Lo escrito a mano tiene prioridad. Con `ALMA_API_KEY`, la guía muestra si hay
ejemplares disponibles y en qué biblioteca (API de Alma, `expand=p_avail`); se pide después de cargar la página, solo
para los libros guardados en el bloque, y se guarda en caché 30 minutos (los datos por ISBN, una semana). Si el
catálogo no responde, la guía se muestra igual. Las claves van solo en `.env.local` del servidor. Los datos por DOI se piden a doi.org (formato CSL JSON, sin clave).

#### Buscador de libros

Bajo la lista del bloque Libros hay un buscador para agregar varios libros de una vez: se marcan los resultados y
«Agregar los marcados» los suma a la lista con sus datos (nada se guarda hasta «Guardar»; los que ya están en la lista
aparecen marcados).

- **Catálogo (Primo VE)**: por título exacto, autor y/o editorial (libros de la institución y del índice central).
  Primo no busca por editorial: se busca en todos los campos y se filtra por la editorial del registro.
- **Biblioges**: bibliografía de una carrera (agrupada por asignatura) o de una asignatura, por su código; tipo básica,
  complementaria, otra o todas; declarada (la del programa) o disponible (las ediciones de la biblioteca, con su MMS o
  enlace de acceso). Necesita `BIBLIOGES_API_KEY` en `.env.local` (la genera un administrador de Biblioges en
  Administración → API Keys); `BIBLIOGES_API_URL` ya apunta a `https://biblioges.ucn.cl/biblioges/api/v1`.

El desplegable **«Asignar materias a los que agregues»** (también en el buscador de revistas) marca las materias
elegidas en cada libro o revista que se agregue, y al guardar se asignan a esos recursos en la lista A-Z. Sin marcar
ninguna, los nuevos quedan con las materias de la guía.

#### Libros en la lista de recursos

Al guardar un bloque Libros, cada libro se incorpora a la lista de recursos bibliográficos (A-Z) como recurso de tipo
Libros. Si ya estaba (mismo ISBN de 10 o 13 dígitos, impreso o electrónico; mismo DOI o enlace; o, sin identificadores,
mismo título), se vincula con ese recurso sin modificar sus datos. El recurso nuevo enlaza al enlace propio del libro o
a su DOI (con proxy), o a su registro en Primo VE (sin proxy). Cada libro del bloque tiene un selector de **materias**,
que son las del recurso: en un libro nuevo sin materias elegidas se usan las de la guía. Quitar un libro del bloque no
lo borra de la lista. Para incorporar los libros guardados antes de esta función (se puede repetir sin duplicar):

```bash
php bin/console app:incorporar-libros
```

### API pública e incrustación

API de solo lectura en JSON, con CORS abierto y solo con contenido público (guías publicadas no restringidas):

| Dirección | Contenido |
| --- | --- |
| `/api/v1/guias` | Guías; filtros `materia` (slug), `q` e `idioma` |
| `/api/v1/guias/{slug}` | Una guía con pestañas, secciones y bloques |
| `/api/v1/recursos` | Lista A-Z por páginas; filtros `q`, `letra`, `materia`, `tipo`, `acceso`, `idioma`, `pagina`, `por_pagina` |

Cualquier bloque de una guía pública se puede mostrar en otra web (un curso de Moodle, el sitio de una carrera): en el
editor, **Incrustar** entrega el código. Con `/incrustar.js` el iframe ajusta su alto solo:

```html
<div data-guias-bloque="12"></div>
<script src="https://<servidor>/incrustar.js" async></script>
```

o un `<iframe src="https://<servidor>/es/incrustar/bloque/12">` para sitios que no aceptan scripts. Esas páginas se
pueden enmarcar desde cualquier origen (`Content-Security-Policy: frame-ancestors *`).

### Moodle (LTI 1.3)

La plataforma es una herramienta externa LTI 1.3 con Deep Linking: el docente agrega «Guías temáticas» a su curso, elige
la guía y los estudiantes la abren desde el curso. El administrador de Moodle registra la herramienta con estas
direcciones: inicio de sesión `/lti/login`, lanzamiento `/lti/lanzar`, Deep Linking `/lti/vincular` y llaves públicas
`/lti/jwks`. Los datos que entrega Moodle al registrarla van en el grupo «Moodle (LTI 1.3)» de Integraciones. Moodle y
la plataforma deben poder alcanzarse mutuamente, así que la prueba real se hará en producción con dominio público.

### WordPress

El plugin `integraciones/wordpress/guias-ucn` (se descarga desde Integraciones con la dirección del servidor ya puesta)
agrega estos códigos cortos al sitio de Bibliotecas: `[guias_ucn]` (lista de guías), `[guia_ucn slug="…"]` (una guía),
`[guia_ucn_bloque id="…"]` (un bloque) y `[recursos_ucn]` (recursos A-Z). Usa la API pública, nunca muestra guías
restringidas y guarda los datos en caché los minutos indicados en sus ajustes. Detalles en su `readme.txt`.

CKEditor 5 (licencia GPL 2+) y SortableJS (MIT) están copiados en `assets/lib/`, sin depender de un CDN.
