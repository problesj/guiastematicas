# 3. Instalación en otro servidor

Este capítulo explica cómo montar la plataforma en un servidor nuevo (por ejemplo, el de producción de la UCN),
cómo pasarle los datos de otro servidor y cómo mantenerla. Los comandos se ejecutan como un usuario con `sudo`.

## 3.1 Requisitos

| Recurso | Mínimo recomendado |
| ------------ | ---------------------------- |
| Sistema | Ubuntu Server 26.04 LTS, recién instalado, con acceso a internet (paquetes, GitHub, Composer) |
| CPU y memoria | 2 vCPU y 4 GB de RAM |
| Disco | 20 GB (más el espacio de los respaldos en `/var/backups/guias`) |
| Red | Puertos 22, 80 y 443 abiertos; para producción, un nombre DNS que apunte al servidor |
| Salida a internet desde el servidor | `github.com`, `packagist.org`/`repo.packagist.org`, `cdn.jsdelivr.net` y `data.jsdelivr.com` (importmap al desplegar), y los servicios que se usen: Primo/Alma (`*.exlibrisgroup.com`), Biblioges, `generativelanguage.googleapis.com` (Gemini), `oauth2.googleapis.com` (Google), LDAP y SMTP de la UCN |

Software que se instala (lo hace `scripts/instalar-servidor.sh`): Apache 2.4 con `proxy_fcgi`, `rewrite`, `headers`
y `ssl`; PHP 8.5 FPM y CLI con `mysql`, `intl`, `mbstring`, `xml`, `zip`, `curl`, `gd`, `ldap`, `apcu` e `imagick`;
MySQL 8.4; Composer; Git; certbot; ufw; fail2ban y actualizaciones automáticas de seguridad.

## 3.2 Opción A: instalación en un solo paso (recomendada)

`scripts/montar-piloto.sh` hace todo: paquetes, base de datos con contraseña aleatoria, código, `.env.local`,
dependencias, migraciones, primer administrador y respaldo diario.

```bash
curl -fsSL -o montar-piloto.sh \
  https://raw.githubusercontent.com/problesj/guiastematicas/main/scripts/montar-piloto.sh
sudo VERSION=main SERVIDOR=guias.ucn.cl ADMIN_EMAIL=correo@ucn.cl bash montar-piloto.sh
```

| Variable | Para qué | Por omisión |
| ------------ | ---------------------------- | ------------ |
| `ADMIN_EMAIL` | Correo del primer administrador (obligatoria) | — |
| `ADMIN_NOMBRE`, `ADMIN_APELLIDOS` | Su nombre | «Administración Guías» |
| `VERSION` | Rama o etiqueta a instalar. **Usa `main`** para la versión actual | `piloto-2026-11-10` |
| `SERVIDOR` | Nombre o IP con que se entra (va al `ServerName` de Apache) | IP principal |
| `RESTAURAR_BD`, `RESTAURAR_ARCHIVOS` | Respaldos de otro servidor para partir con sus datos (ver 3.5) | — |
| `APP_DIR` | Carpeta de la aplicación | `/var/www/guias` |
| `REPO` | Repositorio | GitHub `problesj/guiastematicas` |

Al terminar muestra la dirección y el resultado de `/salud`. Las contraseñas generadas (base de datos y
administrador) quedan **solo** en `/root/guias-credenciales.txt`. Si el script se corta después de crear
`.env.local`, se puede volver a ejecutar igual: retoma desde las dependencias.

Después sigue con [3.4 Configuración posterior](#34-configuración-posterior).

## 3.3 Opción B: paso a paso

### 1. Paquetes, Apache, PHP, MySQL y cortafuegos

```bash
sudo apt-get update && sudo apt-get install -y git
sudo git clone https://github.com/problesj/guiastematicas.git /var/www/guias
cd /var/www/guias
sudo DB_PASSWORD='<contraseña segura para MySQL>' bash scripts/instalar-servidor.sh
```

El script instala los paquetes, activa el VirtualHost `deploy/apache/guias.conf` (desactiva el sitio por omisión),
crea la base `guias` y el usuario `guias@localhost` con esa contraseña, sube los límites de PHP
(`/etc/php/8.5/fpm/conf.d/90-guias.ini`: archivos de 16 MB y 512 MB de memoria) y abre en ufw solo SSH y Apache. Variables
opcionales: `DB_NAME`, `DB_USER`, `APP_DIR`.

Edita el nombre del sitio en `/etc/apache2/sites-available/guias.conf` (`ServerName guias.ucn.cl`) y recarga:
`sudo systemctl reload apache2`.

### 2. Archivo `.env.local`

Crea `/var/www/guias/.env.local` (no va al repositorio):

```bash
APP_ENV=prod
APP_SECRET=<cadena aleatoria de 48 caracteres: openssl rand -hex 24>
# Reemplaza CLAVE por la contraseña de MySQL
DATABASE_URL="mysql://guias:CLAVE@127.0.0.1:3306/guias?serverVersion=8.4.11&charset=utf8mb4"
DEFAULT_URI=https://guias.ucn.cl

# Proxy y catálogo (también se pueden definir en Administración → Integraciones)
PROXY_URL="https://itmsp.museknowledge.com/UCN?qurl={url}"
PRIMO_URL=https://ucn.primo.exlibrisgroup.com
PRIMO_VID=56UCN_INST:UCN
```

```bash
sudo chown root:www-data /var/www/guias/.env.local
sudo chmod 640 /var/www/guias/.env.local
```

Las demás variables (claves de Ex Libris, Biblioges, Gemini, LDAP, Google, SMTP, LTI) están en
[1.7](01-arquitectura.md#17-configuración-variables-de-entorno-y-ajustes-del-panel). Lo más cómodo es escribirlas
después en el panel, donde quedan cifradas.

> Guarda `APP_SECRET` en un lugar seguro: si cambia, las claves guardadas en el panel dejan de poder leerse y las
> sesiones abiertas se cierran.

### 3. Desplegar el código

```bash
sudo bash /var/www/guias/scripts/desplegar.sh main
```

`desplegar.sh` deja el código en la rama o etiqueta indicada y ejecuta, con `APP_ENV=prod`:

1. `composer install --no-dev --optimize-autoloader`
2. `importmap:install` y `asset-map:compile` (JS y CSS a `public/assets`)
3. `doctrine:migrations:migrate` (crea las tablas y carga las 334 materias ASJC)
4. `cache:clear` y `cache:warmup`
5. `app:incorporar-libros`
6. Crea `public/uploads/{personal,guias,sitio}`, da a `www-data` la propiedad de `var/`, `public/assets` y
   `public/uploads`, y recarga PHP-FPM.

### 4. Primer administrador

```bash
cd /var/www/guias
sudo -u www-data php bin/console app:crear-administrador correo@ucn.cl "Nombre" "Apellidos"
```

Pide la contraseña sin mostrarla (mínimo 12 caracteres).

### 5. HTTPS (producción)

Con el nombre DNS ya apuntando al servidor:

```bash
sudo certbot --apache -d guias.ucn.cl
```

certbot crea el VirtualHost 443, redirige HTTP a HTTPS y programa la renovación automática.

### 6. Respaldo diario

```bash
echo "30 2 * * * root /var/www/guias/scripts/respaldar.sh >/var/log/guias-respaldo.log 2>&1" \
  | sudo tee /etc/cron.d/guias-respaldo
sudo chmod 644 /etc/cron.d/guias-respaldo
```

`respaldar.sh` guarda en `/var/backups/guias` la base (`bd_AAAA-MM-DD_hhmm.sql.gz`, con `mysqldump
--single-transaction`) y los archivos subidos (`archivos_….tar.gz`), y borra los de más de 30 días. **Copia esa
carpeta a otro equipo** (por ejemplo con rsync desde un servidor de respaldo): un respaldo dentro del mismo servidor no
protege contra la pérdida del servidor.

### 7. Comprobar

```bash
curl -s http://127.0.0.1/salud -H 'Host: guias.ucn.cl'
# Respuesta esperada: {"estado":"ok","base_de_datos":"ok"}
```

Entra a `https://guias.ucn.cl/es/acceso` con el administrador.

## 3.4 Configuración posterior

En el panel, como administrador:

1. **Cambiar contraseña** del administrador (si la generó el script).
2. **Administración → Integraciones:** proxy, Primo/Alma, Biblioges, LDAP, Google, correo SMTP (con **Enviar correo
   de prueba**), Gemini y Moodle. Ver el [capítulo 5 del manual](../manual/05-administrador.md#57-integraciones).
3. **Administración → Apariencia:** nombre del sitio, logo, portada y colores.
4. **Usuarios, departamentos y materias propias** (clasificadas en categorías ASJC).
5. Si se usa Google: en la consola de Google Cloud, agrega la URI de redirección
   `https://guias.ucn.cl/conectar/google/verificar`.
6. Si se usa Moodle: registrar la herramienta con `https://guias.ucn.cl/lti/login`, `/lti/lanzar`, `/lti/vincular`
   y `/lti/jwks`, y copiar en Integraciones los datos que entrega Moodle. Moodle y la plataforma deben alcanzarse
   entre sí (no sirve un servidor en una red privada sin salida).
7. Si se usa WordPress: descargar el plugin desde Integraciones (ya lleva la dirección del servidor) e instalarlo.

## 3.5 Pasar los datos desde otro servidor

Para llevar las guías, recursos y cuentas de un servidor a otro (por ejemplo, del piloto a producción):

1. En el **origen**: `sudo bash /var/www/guias/scripts/respaldar.sh`.
2. Copia los dos archivos más recientes de `/var/backups/guias` al destino (`scp`).
3. En el **destino** (ya instalado y en la misma versión del código o una posterior):

```bash
sudo bash /var/www/guias/scripts/restaurar.sh \
  bd_2026-11-19_0230.sql.gz archivos_2026-11-19_0230.tar.gz
```

`restaurar.sh` pide confirmación (o `CONFIRMAR=si`), respalda primero los datos actuales del destino, reemplaza la base
y `public/uploads`, aplica las migraciones pendientes, limpia la caché y ajusta permisos.

Qué hay que saber:

- Las **cuentas y contraseñas** pasan con la base: se entra con las del servidor de origen.
- Las **claves cifradas** del panel (Integraciones, llave LTI) se borran porque el destino tiene otro `APP_SECRET`.
  Vuelve a escribirlas en Integraciones. La llave LTI se genera de nuevo sola; Moodle la toma de `/lti/jwks`.
- Si quieres conservar las claves (por ejemplo, al cambiar el servidor de producción por otro), copia el mismo
  `APP_SECRET` al `.env.local` nuevo y restaura a mano en vez de usar el script:
  `gunzip -c bd_….sql.gz | sudo mysql guias`, luego `desplegar.sh main`.
- Instalación nueva con datos desde el principio: `montar-piloto.sh` con `RESTAURAR_BD` y `RESTAURAR_ARCHIVOS`.

## 3.6 Actualizar a una nueva versión

```bash
sudo bash /var/www/guias/scripts/respaldar.sh      # por si hay que volver atrás
sudo bash /var/www/guias/scripts/desplegar.sh main  # o una etiqueta, p. ej. v1.2.0
```

Es seguro repetirlo. Las migraciones se aplican solas. Para **volver a una versión anterior**: despliega la etiqueta
anterior y, si la nueva versión cambió el esquema, restaura el respaldo previo con `restaurar.sh`.

Se recomienda etiquetar en Git cada versión que pase a producción (`git tag v1.0.0 && git push --tags`) y desplegar
por etiqueta en producción y por `main` en pruebas.

## 3.7 Varios entornos

| Entorno | Uso | Cómo se actualiza |
| ------------ | ---------------------------- | ---------------------------- |
| Desarrollo | Equipo de cada desarrollador (Docker, ver capítulo 5) | `git pull` |
| Pruebas | VM interna; recibe cada cambio de `main` | `desplegar.sh main` |
| Piloto | Personal de la biblioteca | `desplegar.sh main` cuando se aprueba |
| Producción | Público, con dominio y HTTPS | `desplegar.sh <etiqueta>` |

Cada entorno tiene su propio `.env.local`, `APP_SECRET`, base de datos y respaldos.

## 3.8 Problemas frecuentes

| Síntoma | Causa probable | Qué hacer |
| ------------ | ---------------------------- | ---------------------------- |
| Error 500 en todo el sitio | Permisos de `var/` o caché antigua | `sudo chown -R www-data:www-data var && sudo -u www-data php bin/console cache:clear` |
| `/salud` responde `degradado` | MySQL detenido o `DATABASE_URL` incorrecta | `systemctl status mysql`; revisar `.env.local` |
| Página sin estilos | Faltan los assets compilados | Volver a ejecutar `desplegar.sh` |
| `composer install` o `importmap:install` fallan | El servidor no sale a internet | Abrir la salida a Packagist, GitHub y jsDelivr |
| Las claves de Integraciones aparecen vacías | `APP_SECRET` cambió | Volver a escribirlas en el panel |
| No llegan correos | SMTP sin configurar o bloqueado | **Enviar correo de prueba** en Integraciones; revisar `var/log/prod-*.log` |
| Una imagen o una importación no sube | Límite de PHP (servidores instalados antes de noviembre de 2026) | Crear `/etc/php/8.5/fpm/conf.d/90-guias.ini` con `upload_max_filesize = 16M`, `post_max_size = 16M` y `memory_limit = 512M`, y `sudo systemctl restart php8.5-fpm` |
| Inicio con LDAP falla | Datos LDAP o red | Probar con `ldapsearch` desde el servidor; revisar cifrado (`none`, `ssl`, `tls`) |

Registros: `var/log/prod-AAAA-MM-DD.log` (aplicación, 14 días), `/var/log/apache2/guias-error.log` y `guias-access.log`,
`/var/log/guias-respaldo.log`.
