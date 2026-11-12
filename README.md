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

Un administrador puede definir en **Administración → Integraciones** el proxy AccesUM, Primo VE y la API de Ex Libris,
Biblioges, LDAP y Google. Lo definido en el panel reemplaza a la variable de entorno del mismo nombre; lo que queda
vacío usa el `.env.local` del servidor. Las claves se guardan cifradas (sodium) con una llave derivada de `APP_SECRET`
y nunca se vuelven a mostrar: si `APP_SECRET` cambia, hay que escribirlas de nuevo. Los servicios leen estos valores
con `%env(ajuste:NOMBRE)%` (`App\Configuracion\AjusteEnvVarProcessor`).

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
| Recursos | Recursos bibliográficos elegidos de la lista A-Z, en el orden indicado, con o sin descripción |
| Perfil | Ficha de un bibliotecario del directorio |
| Video | YouTube o Vimeo |
| Búsqueda en el catálogo | Caja de búsqueda que abre Primo VE (`PRIMO_URL` y `PRIMO_VID` en `.env.local`) |
| Lista de enlaces | Sitios web externos con descripción opcional |
| Libros | Lista por ISBN o DOI: el botón «Buscar datos» (y el guardado) completa título, autor, editorial y año desde el catálogo (ver abajo) o desde doi.org; portada de Open Library y enlace al registro en Primo VE o al DOI |
| Diapositivas | Carrusel de imágenes subidas (`public/uploads/guias/d{id}-*`), cada una con texto y enlace opcionales; las que dejan de usarse se borran |

Los recursos de acceso abierto llevan la marca «Recurso libre», y el perfil muestra la ubicación (oficina) y, si se
indicó en la ficha de la persona, un botón «Agenda una cita».

Cada guía puede llevar una imagen de cabecera (banner, 4:1) y un ícono cuadrado, que se guardan en `public/uploads/guias` (JPG, PNG o WebP; sin SVG).

Permisos: un **Editor** crea guías y es su responsable (edita datos, publica y elimina); los **coeditores** que elija
editan el contenido. El **Administrador** edita todas y puede cambiar el responsable. Una guía nace en borrador y solo se
ve en la vista previa del panel hasta que se publica.

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

CKEditor 5 (licencia GPL 2+) y SortableJS (MIT) están copiados en `assets/lib/`, sin depender de un CDN.
