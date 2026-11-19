# 5. Desarrollo

## 5.1 Entorno local con Docker

Requisitos: Git y Docker con Compose.

```bash
git clone https://github.com/problesj/guiastematicas.git && cd guiastematicas
docker compose up -d --build
docker compose exec app composer install
docker compose exec app chown -R www-data:www-data var
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
# Datos de ejemplo (solo desarrollo)
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction
```

- Sitio: http://localhost:8080 (y `/salud`). MySQL en `127.0.0.1:3306`, base, usuario y contraseña `guias`.
- `compose.yaml` levanta `app` (imagen `docker/php/Dockerfile`: Apache 2.4 + PHP 8.5 con las mismas extensiones que
  el servidor) y `db` (MySQL 8.4). El código se monta como volumen: los cambios se ven al recargar.
- Los datos de ejemplo (`src/DataFixtures/AppFixtures.php`) crean departamentos, materias, recursos, guías y cuentas
  con la contraseña `guias-desarrollo`: `admin@ucn.cl` (administrador), `editora1@ucn.cl`, `editor2@ucn.cl`,
  `editora3@ucn.cl` (editores), `colaborador@ucn.cl` (colaborador) y `lectora@ucn.cl` (lector). **Nunca** se cargan en
  un servidor.
- Para probar integraciones, crea `.env.local` (no se sube al repositorio) con las claves de prueba.

Sin Docker basta PHP 8.5 con las extensiones del capítulo 1, Composer y MySQL 8.4 (o SQLite para pruebas rápidas):
`symfony serve` o `php -S 127.0.0.1:8000 -t public`.

## 5.2 Pruebas

```bash
docker compose exec app php bin/phpunit
```

| Carpeta de `tests/` | Qué prueba |
| ------------ | ---------------------------- |
| `Admin/`, `Panel/`, `Guias/`, `Recursos/`, `Personal/`, `Seguridad/`, `Lti/`, `Controller/` | Pruebas funcionales: piden páginas con el cliente HTTP de Symfony y revisan respuestas, permisos y datos guardados |
| `Unidad/` | Clases sin base de datos: ASJC, autores, lectura de bibliografía, paginación, proxy, recursos, videos |
| `Comando/` | Comandos de consola |
| `CreaDatosTrait.php` | Ayudas para crear usuarios, guías, materias y recursos en cada prueba |
| `CatalogoFalso.php` | Sustituye Primo/Alma por respuestas fijas: las pruebas no salen a internet |

Las pruebas usan la base `guias_test` (Doctrine agrega `_test`). Cada prueba crea los datos que necesita.

Antes de subir un cambio, ejecuta también las revisiones de sintaxis:

```bash
php bin/console lint:yaml config translations --parse-tags
php bin/console lint:twig templates
php bin/console lint:container
php bin/console doctrine:schema:validate
```

## 5.3 Integración continua

`.github/workflows/ci.yml` corre en cada pull request y en cada cambio de `main`, con PHP 8.5 y un servicio MySQL
8.4:

1. `composer install`
2. Revisión de sintaxis de YAML, Twig y del contenedor de servicios
3. Migraciones desde cero, `doctrine:schema:validate` y verificación de que quedan las 334 materias ASJC
4. PHPUnit
5. `composer audit` (vulnerabilidades conocidas en las dependencias)

Un pull request se fusiona solo con la integración continua en verde.

## 5.4 Flujo de trabajo

1. Crea una rama desde `main` (`git switch -c mejora-x`).
2. Haz el cambio con sus pruebas. Si cambia el esquema, agrega la migración (capítulo 2.5).
3. Corre las pruebas y las revisiones de sintaxis.
4. Abre un pull request; cuando la integración continua está en verde, se fusiona con *squash*.
5. Despliega en el servidor de pruebas (`desplegar.sh main`) y, cuando se aprueba, en piloto y producción.
6. Si cambia lo que ve el personal, actualiza el manual (`docs/manual`) y regenéralo con
   `python3 scripts/generar-manual.py`; si cambia el modelo, regenera este documento (ver README).

## 5.5 Convenciones del código

- **Idioma:** el código propio (clases, métodos, variables, rutas, plantillas) está en **español**; los textos de la
  interfaz van en `translations/messages.es.yaml` y `messages.en.yaml` (nunca escritos en las plantillas).
- **Controladores** delgados con atributos `#[Route]`; la lógica va en `src/Servicio`. Permisos con `#[IsGranted]` o
  `denyAccessUnlessGranted()` y los voters.
- **Formularios** de Symfony con validación en la entidad o en el formulario; CSRF siempre activo.
- **JavaScript** en controladores Stimulus (`assets/controllers/*_controller.js`), sin compilación. Las librerías se
  agregan con `php bin/console importmap:require <paquete>` y se descargan al desplegar; el navegador nunca las pide a
  un CDN.
- **Alertas y confirmaciones** con SweetAlert2 (controlador `confirmar`), con los botones en el idioma de la página.
- **Acciones de las tablas** del panel como botones con ícono y texto emergente: función Twig `icono()`
  (`App\Twig\IconosAccion`).
- **Accesibilidad** WCAG 2.1 AA: etiquetas en los campos, contraste validado (también en Apariencia), navegación con
  teclado.
- **Secretos**: nunca en el repositorio. Van en `.env.local` del servidor o en el panel (cifrados).

## 5.6 Agregar un tipo de bloque (ejemplo)

1. Agrega el caso a `App\Enum\TipoBloque` (valor en minúsculas) y su nombre en las traducciones.
2. Define los campos del formulario en `App\Form\BloqueType` según el tipo.
3. Convierte lo enviado a JSON en `GuiaController::contenido()` (y valida lo que corresponda).
4. Prepara los datos para mostrar en `App\Servicio\BloquesGuia`.
5. Crea la plantilla `templates/guias/bloques/_<tipo>.html.twig` (sitio, vista previa e incrustación la reutilizan).
6. Agrega pruebas en `tests/Guias/`. No hace falta migración: el contenido vive en `bloque.contenido`.

## 5.7 Regenerar la documentación

```bash
python3 scripts/generar-manual.py           # manual de uso (docs/manual): HTML y Word
python3 scripts/generar-diagrama-er.py      # diagramas ER y diccionario de datos (docs/tecnico)
python3 scripts/generar-manual.py tecnico   # este documento: HTML y Word
```

Necesitan pandoc 3, python-docx y Pillow; los diagramas, además, PHP con las dependencias instaladas y Node con
Playwright para los PNG (sin Node se generan solo los SVG). Las capturas del manual se toman con Playwright sobre el
sitio con los datos de ejemplo.
