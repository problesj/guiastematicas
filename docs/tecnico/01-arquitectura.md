# 1. Arquitectura

## 1.1 Tecnologías

| Capa | Tecnología | Notas |
| ------------ | ------------ | ---------------------------- |
| Sistema operativo | Ubuntu 26.04 LTS | Probado en la VM de pruebas y en el servidor piloto |
| Servidor web | Apache 2.4 + PHP-FPM | VirtualHost en `deploy/apache/guias.conf` |
| Lenguaje | PHP 8.5 | Extensiones: intl, pdo_mysql, mbstring, xml, zip, curl, gd, ldap, apcu, sodium |
| Framework | Symfony 7.4 LTS | Controladores con atributos, formularios, seguridad, traducciones, rate limiter, mailer, HTML sanitizer |
| Base de datos | MySQL 8.4 LTS | Doctrine ORM 3 y Doctrine Migrations; `utf8mb4_unicode_ci` |
| Interfaz | Twig + AssetMapper | Sin Node ni compilación: los JS se sirven como módulos ES con importmap |
| JavaScript | Stimulus y Turbo (Symfony UX) | Controladores en `assets/controllers` |
| Librerías JS | CKEditor 5, SweetAlert2, SortableJS | Por importmap (`importmap.php`), descargadas al desplegar; nunca desde un CDN en el navegador |
| Planillas | PhpSpreadsheet | Importar y exportar recursos (Excel, ODS, CSV) |
| Autenticación externa | Symfony LDAP, league/oauth2-google, firebase/php-jwt | LDAP UCN, Google y LTI 1.3 |

## 1.2 Componentes

```
 Navegador (público y personal) · Moodle (LTI) · WordPress (plugin, iframes)
                               │ HTTP(S)
                               ▼
 ┌──────────────────────────────────────────┐
 │ Apache 2.4 + certbot                     │
 │ public/: index.php, assets/, uploads/    │
 └────────────────────┬─────────────────────┘
                      │ FastCGI
 ┌────────────────────▼─────────────────────┐     ┌─────────────────────┐
 │ PHP-FPM 8.5 · Symfony 7.4                │────▶│ MySQL 8.4 (guias)   │
 │ · sitio público, panel, admin, API       │     └─────────────────────┘
 │ · servicios (catálogo, IA, correo…)      │────▶ Primo VE y Alma
 │ · seguridad (formulario, LDAP, Google)   │────▶ Biblioges
 │ · herramienta LTI 1.3                    │────▶ Gemini
 │ var/: caché, logs, importaciones         │────▶ LDAP, Google, SMTP
 └──────────────────────────────────────────┘
```

Es una aplicación monolítica: un solo proyecto Symfony sirve el sitio público, el panel de trabajo, la API JSON, los
bloques incrustables y la herramienta LTI. No hay colas ni procesos en segundo plano; lo único programado es el
respaldo diario (cron).

## 1.3 Estructura del repositorio

| Carpeta o archivo | Contenido |
| ------------ | ---------------------------- |
| `assets/` | JS (controladores Stimulus), CSS (`styles/`), librerías locales (`lib/`), íconos |
| `bin/console` | Consola de Symfony |
| `config/` | Configuración de Symfony (`packages/`, `routes/`, `services.yaml`) |
| `deploy/apache/guias.conf` | VirtualHost para el servidor |
| `docker/`, `compose.yaml` | Entorno de desarrollo con Docker (Apache + PHP 8.5 y MySQL 8.4) |
| `docs/` | Manual de uso (`manual/`), este documento (`tecnico/`) y la planificación (`PLAN.md`) |
| `migrations/` | Migraciones de Doctrine (una por cambio de esquema) |
| `public/` | Raíz web: `index.php`, `assets/` compilados, `uploads/` (archivos subidos, fuera del repositorio) |
| `scripts/` | Instalación, despliegue, respaldo y restauración; generación del manual y de este documento |
| `src/` | Código PHP (ver tabla siguiente) |
| `integraciones/wordpress/guias-ucn` | Plugin de WordPress (el panel lo entrega en .zip ya configurado) |
| `templates/` | Plantillas Twig: `inicio/`, `guias/`, `recursos/`, `personal/`, `panel/`, `admin/`, `incrustar/`, `correo/`… |
| `tests/` | Pruebas PHPUnit (funcionales con el cliente HTTP de Symfony y unitarias) |
| `translations/` | Textos de la interfaz en español e inglés (`messages.es.yaml`, `messages.en.yaml`) |

### Código PHP (`src/`)

| Espacio de nombres | Responsabilidad |
| ------------ | ---------------------------- |
| `Controller\` | Sitio público: portada, guías, recursos A-Z, directorio, consultas, sugerencias, búsqueda, incrustar, salud |
| `Controller\Panel\` | Panel del personal: guías y su editor, recursos, autores, editoriales, consultas, sugerencias, asistente IA |
| `Controller\Admin\` | Administración: usuarios, departamentos, materias, países, apariencia, integraciones |
| `Controller\Api\` | API pública de solo lectura `/api/v1` |
| `Controller\LtiController` | Herramienta LTI 1.3 (login, lanzamiento, deep linking, JWKS) |
| `Entity\`, `Repository\` | Entidades de Doctrine y sus consultas (ver capítulo 2) |
| `Enum\` | Enumeraciones guardadas como texto (roles, estados, tipos) |
| `Form\` | Formularios de Symfony (`GuiaType`, `BloqueType`, `RecursoType`, `MateriasType`…) |
| `Security\` | Autenticadores (formulario + LDAP, Google), `GuiaVoter` (VER, EDITAR, GESTIONAR), `SugerenciaVoter` |
| `Servicio\` | Lógica de negocio: catálogo Primo/Alma, Biblioges, Gemini, bloques, contenido compartido, proxy, imágenes… |
| `Servicio\Importacion\`, `Servicio\Exportacion\` | Importar (Excel, ODS, CSV, BibTeX, RIS) y exportar (Excel, RIS) recursos |
| `Clasificacion\` | Esquema ASJC (334 categorías, 27 campos, 5 grandes áreas) y su sincronización con `materia` |
| `Configuracion\` | Ajustes editables desde el panel (`Ajustes`, `Apariencia`) y el procesador `%env(ajuste:…)%` |
| `Correo\` | Envío de correos (`Cartero`) con SMTP configurado en el panel o `CORREO_DSN` |
| `Captcha\` | Verificación anti-robots propia (prueba de trabajo, sin servicios externos) |
| `Lti\` | Verificación de mensajes LTI, llaves RSA de la herramienta y deep linking |
| `Paginacion\` | Paginación y filtros recordados en la sesión |
| `Twig\` | Funciones y filtros propios (íconos de acción, apariencia, enlaces con proxy) |
| `Command\` | Comandos de consola (ver 1.6) |
| `DataFixtures\` | Datos de ejemplo para desarrollo |

## 1.4 Rutas principales

Las rutas públicas llevan el idioma: `/{_locale}/…` con `es` o `en`.

| Ruta | Qué es |
| ------------ | ---------------------------- |
| `/es`, `/en` | Portada |
| `/es/guias`, `/es/guias/{slug}/{pestana}` | Lista de guías y una guía (la pestaña es opcional) |
| `/es/recursos-bibliograficos` y `/es/recursos-bibliograficos/{bases-de-datos\|libros\|revistas}` | Lista A-Z y accesos por tipo |
| `/recursos/{id}/ir` | Redirección al recurso (con proxy si corresponde) y conteo de clics |
| `/es/personal`, `/es/personal/{slug}` | Directorio de bibliotecarios y ficha con el formulario de consulta |
| `/es/consulta/{token}` | Conversación privada de una consulta |
| `/es/recursos-bibliograficos/sugerir` | Formulario público de sugerencias |
| `/es/incrustar/bloque/{id}` | Bloque para mostrar en otra web (iframe) |
| `/es/acceso`, `/conectar/google` | Inicio de sesión |
| `/es/buscar`, `/es/catalogo` | Búsqueda en el sitio y en el catálogo |
| `/es/panel/…` | Panel del personal (rol colaborador o superior) |
| `/es/admin/…` | Administración (rol administrador) |
| `/api/v1/guias`, `/api/v1/guias/{slug}`, `/api/v1/recursos` | API JSON pública |
| `/lti/login`, `/lti/lanzar`, `/lti/vincular`, `/lti/jwks` | LTI 1.3 para Moodle |
| `/salud` | Comprobación de funcionamiento: `{"estado":"ok","base_de_datos":"ok"}` (503 si la base no responde) |

Para ver todas: `php bin/console debug:router`.

## 1.5 Seguridad

- **Roles** (`App\Enum\Rol`): `ROLE_LECTOR` < `ROLE_COLABORADOR` < `ROLE_EDITOR` < `ROLE_ADMINISTRADOR`, con jerarquía
  en `config/packages/security.yaml`. `/{idioma}/panel` exige colaborador y `/{idioma}/admin` administrador.
- **Permisos por guía** (`GuiaVoter`): `VER` (publicada y pública, o restringida con el usuario asignado), `EDITAR`
  (responsable, coeditor o administrador) y `GESTIONAR` (responsable o administrador: datos, publicar, eliminar).
- **Inicio de sesión**: formulario con contraseña propia (hash de Symfony, mínimo 12 caracteres) o LDAP, y Google
  OAuth. No hay registro público; las cuentas las crea un administrador. Se limita el número de intentos.
- **CSRF** en todos los formularios y acciones del panel; **HTML sanitizer** para el texto enriquecido de los bloques.
- **Formularios públicos** (consultas y sugerencias): captcha propio de prueba de trabajo y límite por IP
  (`config/packages/rate_limiter.yaml`).
- **Secretos**: claves de API y contraseñas viven en `.env.local` del servidor (permisos 640, fuera del repositorio) o
  en la tabla `ajuste` cifradas con sodium (XSalsa20-Poly1305) con una clave derivada de `APP_SECRET`. Nunca se
  muestran en el panel.
- **Archivos subidos**: se validan tipo y tamaño, se renombran, y Apache no ejecuta PHP en `public/uploads`.
- **Cabeceras**: `X-Content-Type-Options`, `Referrer-Policy` y `X-Frame-Options` (salvo en `/incrustar`, que envía
  `frame-ancestors *` para poder mostrarse en otras webs).

## 1.6 Comandos de consola propios

| Comando | Para qué |
| ------------ | ---------------------------- |
| `app:crear-administrador correo "Nombre" "Apellidos"` | Crea (o reactiva) un administrador; pide la contraseña oculta |
| `app:materias-asjc` | Crea o actualiza las 334 materias base ASJC (lo hace también la migración) |
| `app:incorporar-libros` | Incorpora a la lista A-Z los libros de los bloques Libros que aún no estén (lo corre `desplegar.sh`) |
| `app:probar-catalogo` | Busca un ISBN en Primo VE y su disponibilidad en Alma (prueba la configuración) |

## 1.7 Configuración: variables de entorno y ajustes del panel

Symfony lee `.env` (valores por omisión, en el repositorio, sin secretos) y `.env.local` (propio de cada servidor).
Muchas variables se pueden además definir desde **Administración → Integraciones**: el valor del panel (tabla
`ajuste`) tiene prioridad, y si está vacío se usa el de `.env.local`. Esto lo hace el procesador
`%env(ajuste:NOMBRE)%` de `App\Configuracion\AjusteEnvVarProcessor`.

| Variable | Obligatoria | Uso |
| ------------ | :--: | ---------------------------- |
| `APP_ENV` | sí | `prod` en servidores |
| `APP_SECRET` | sí | Cadena aleatoria (48+ caracteres). Firma sesiones y CSRF y deriva la clave de cifrado de los ajustes |
| `DATABASE_URL` | sí | `mysql://usuario:clave@127.0.0.1:3306/guias?serverVersion=8.4.11&charset=utf8mb4` |
| `DEFAULT_URI` | | URL pública (para enlaces en correos y comandos), p. ej. `https://guias.ucn.cl` |
| `PROXY_URL` | | Plantilla del proxy AccesUM: `https://itmsp.museknowledge.com/UCN?qurl={url}` |
| `PRIMO_URL`, `PRIMO_VID` | | Catálogo Primo VE (búsqueda, categorías de revistas) |
| `EXLIBRIS_API_URL`, `PRIMO_API_KEY`, `ALMA_API_KEY` | | API de Ex Libris (libros por ISBN, disponibilidad) |
| `BIBLIOGES_API_URL`, `BIBLIOGES_API_KEY` | | Bibliografía de carreras |
| `LDAP_URL`, `LDAP_CIFRADO`, `LDAP_DN_USUARIO` o `LDAP_BASE_DN` + `LDAP_FILTRO`, `LDAP_BIND_DN`, `LDAP_BIND_PASSWORD` | | Inicio de sesión institucional |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_DOMINIO` | | Inicio de sesión con Google |
| `CORREO_DSN` | | Si se define (p. ej. `smtp://usuario:clave@host:587`), reemplaza la configuración SMTP del panel |
| `GEMINI_API_KEY`, `GEMINI_MODELO` | | Asistente «Nueva guía con IA» (por omisión `gemini-2.5-flash`) |
| `LTI_ISSUER`, `LTI_CLIENT_ID`, `LTI_DEPLOYMENT_ID`, `LTI_AUTH_URL`, `LTI_JWKS_URL` | | Registro de la herramienta en Moodle |

Los ajustes de **Apariencia** (título, colores, logo, presentación de listas) se guardan solo en la tabla `ajuste`.
La llave privada RSA de LTI (`LTI_LLAVE_PRIVADA`) se genera sola la primera vez y se guarda cifrada.

## 1.8 Integraciones externas

| Servicio | Servicio PHP | Cómo se usa | Si no está configurado |
| ------------ | ------------ | ---------------------------- | ---------------------------- |
| Proxy AccesUM | `ProxyAccesum` | Arma el enlace público de los recursos con «Requiere proxy» | Se enlaza la URL original |
| Primo VE | `Catalogo` | Búsqueda en el catálogo, libros y revistas por título/ISBN/ISSN, árbol de categorías de revistas (API pública `primaws`) | No se ofrece el buscador |
| Alma | `Catalogo` | Disponibilidad de ejemplares impresos | No se muestra la disponibilidad |
| Biblioges | `Biblioges` | Bibliografía básica de carreras y asignaturas | Se oculta la opción |
| Gemini | `Gemini`, `AsistenteGuias` | Elegir recursos y redactar textos en el asistente; solo se envían materias, carreras y títulos/descripciones de recursos | La guía se arma sin IA |
| SMTP | `Correo\Cartero` | Avisos de consultas | La consulta se registra, sin correo |
| LDAP / Google | `Security\…` | Inicio de sesión | Solo contraseña propia |
| Moodle | `Lti\…` | LTI 1.3 con deep linking | — |
| WordPress | plugin en `integraciones/wordpress` | Códigos cortos que leen la API pública | — |

Las respuestas de Primo y Alma se guardan en la caché `cache.catalogo` (sistema de archivos, en `var/cache`).
