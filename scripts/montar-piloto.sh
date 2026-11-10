#!/usr/bin/env bash
# Monta Guías temáticas completo en un servidor Ubuntu 26.04 LTS limpio, en un solo paso:
# paquetes, Apache + PHP-FPM, MySQL, código en la versión indicada, .env.local, migraciones, administrador inicial
# y respaldo diario. Pensado para el servidor piloto (10.21.0.102) y reutilizable para producción.
#
# Uso (como root, en el servidor):
#   curl -fsSL https://raw.githubusercontent.com/problesj/guiastematicas/piloto-2026-11-10/scripts/montar-piloto.sh -o montar-piloto.sh
#   ADMIN_EMAIL=correo@ucn.cl bash montar-piloto.sh
#
# Variables (todas opcionales salvo ADMIN_EMAIL):
#   ADMIN_EMAIL          correo del primer administrador (obligatoria)
#   ADMIN_NOMBRE, ADMIN_APELLIDOS
#   VERSION              rama o etiqueta del repositorio (por defecto piloto-2026-11-10, el estado al 10-11-2026)
#   SERVIDOR             nombre o IP con que se entra al sitio (por defecto la IP principal del servidor)
#   RESTAURAR_BD         respaldo bd_*.sql.gz de otro servidor (scripts/respaldar.sh) para partir con sus datos
#   RESTAURAR_ARCHIVOS   respaldo archivos_*.tar.gz (fotos, banners e íconos) del mismo servidor
#   APP_DIR              carpeta de la aplicación (por defecto /var/www/guias)
#
# Las contraseñas generadas (base de datos y administrador) quedan solo en /root/guias-credenciales.txt.
# Las claves de las API de Alma y Primo NO se instalan aquí: agregarlas a mano en $APP_DIR/.env.local.
set -euo pipefail

: "${ADMIN_EMAIL:?Define ADMIN_EMAIL con el correo del primer administrador}"
ADMIN_NOMBRE="${ADMIN_NOMBRE:-Administración}"
ADMIN_APELLIDOS="${ADMIN_APELLIDOS:-Guías}"
VERSION="${VERSION:-piloto-2026-11-10}"
REPO="${REPO:-https://github.com/problesj/guiastematicas.git}"
APP_DIR="${APP_DIR:-/var/www/guias}"
SERVIDOR="${SERVIDOR:-$(hostname -I | awk '{print $1}')}"
CREDENCIALES=/root/guias-credenciales.txt
export DB_NAME="${DB_NAME:-guias}" DB_USER="${DB_USER:-guias}" APP_DIR

paso() { printf '\n==> %s\n' "$*"; }
aleatorio() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

if [[ $EUID -ne 0 ]]; then
    echo "Ejecutar como root (sudo)." >&2
    exit 1
fi
if [[ -f "$APP_DIR/.env.local" ]]; then
    echo "$APP_DIR ya está instalado. Para actualizarlo usar: bash $APP_DIR/scripts/desplegar.sh <versión>" >&2
    exit 1
fi
# os-release se lee en una subshell: define VERSION, que pisaría la versión del código.
SISTEMA="$(. /etc/os-release && echo "${VERSION_ID:-}|${PRETTY_NAME:-desconocido}")"
if [[ "${SISTEMA%%|*}" != "26.04" ]]; then
    echo "Aviso: probado en Ubuntu 26.04; este servidor es ${SISTEMA#*|}." >&2
fi
for respaldo in "${RESTAURAR_BD:-}" "${RESTAURAR_ARCHIVOS:-}"; do
    if [[ -n "$respaldo" && ! -f "$respaldo" ]]; then
        echo "No existe el respaldo $respaldo" >&2
        exit 1
    fi
done

paso "Descargando el código ($VERSION)"
apt-get update
apt-get install -y git openssl curl
install -d "$(dirname "$APP_DIR")"
git clone --branch "$VERSION" "$REPO" "$APP_DIR"
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$APP_DIR"

paso "Instalando Apache, PHP 8.5, MySQL 8.4 y cortafuegos"
DB_PASSWORD="$(aleatorio 32)"
export DB_PASSWORD
bash "$APP_DIR/scripts/instalar-servidor.sh"
sed -i "s/ServerName .*/ServerName ${SERVIDOR}/" /etc/apache2/sites-available/guias.conf
systemctl reload apache2

paso "Configurando $APP_DIR/.env.local"
umask 027
cat > "$APP_DIR/.env.local" <<ENV
APP_ENV=prod
APP_SECRET=$(aleatorio 48)
DATABASE_URL="mysql://${DB_USER}:${DB_PASSWORD}@127.0.0.1:3306/${DB_NAME}?serverVersion=8.4.11&charset=utf8mb4"

# Enlaces de bases de datos a través del proxy AccesUM (MuseKnowledge).
PROXY_URL="https://itmsp.museknowledge.com/UCN?qurl={url}"

# Búsqueda en el catálogo (bloque "Búsqueda en el catálogo" de las guías).
PRIMO_URL=https://ucn.primo.exlibrisgroup.com
PRIMO_VID=56UCN_INST:UCN

# Claves de las API de Ex Libris: completar a mano si se usan (no van en el repositorio).
# EXLIBRIS_API_URL=https://api-na.hosted.exlibrisgroup.com
# ALMA_API_KEY=
# PRIMO_API_KEY=
ENV
umask 022
chown root:www-data "$APP_DIR/.env.local"
chmod 640 "$APP_DIR/.env.local"

if [[ -n "${RESTAURAR_BD:-}" ]]; then
    paso "Restaurando la base de datos desde $RESTAURAR_BD"
    gunzip -c "$RESTAURAR_BD" | mysql "$DB_NAME"
fi
if [[ -n "${RESTAURAR_ARCHIVOS:-}" ]]; then
    paso "Restaurando fotos e imágenes desde $RESTAURAR_ARCHIVOS"
    tar -xzf "$RESTAURAR_ARCHIVOS" -C "$APP_DIR/public"
fi

paso "Instalando dependencias y aplicando migraciones"
bash "$APP_DIR/scripts/desplegar.sh" "$VERSION"

paso "Creando el administrador $ADMIN_EMAIL"
ADMIN_PASSWORD="$(aleatorio 20)"
cd "$APP_DIR"
printf '%s\n' "$ADMIN_PASSWORD" | runuser -u www-data -- env APP_ENV=prod php bin/console app:crear-administrador \
    "$ADMIN_EMAIL" "$ADMIN_NOMBRE" "$ADMIN_APELLIDOS"

umask 077
cat > "$CREDENCIALES" <<CRED
Guías temáticas · $(date '+%Y-%m-%d %H:%M') · versión $(git rev-parse --short HEAD)
Sitio:          http://${SERVIDOR}/
Administrador:  ${ADMIN_EMAIL}
Contraseña:     ${ADMIN_PASSWORD}   (cámbiala en Panel → Cambiar contraseña)
Base de datos:  ${DB_NAME} / usuario ${DB_USER} / contraseña ${DB_PASSWORD}
CRED
umask 022

paso "Programando el respaldo diario (02:30, se guardan 30 días en /var/backups/guias)"
echo "30 2 * * * root $APP_DIR/scripts/respaldar.sh >/var/log/guias-respaldo.log 2>&1" > /etc/cron.d/guias-respaldo
chmod 644 /etc/cron.d/guias-respaldo

paso "Comprobando"
SALUD="$(curl -fsS -H "Host: ${SERVIDOR}" http://127.0.0.1/salud || true)"
echo "Salud: ${SALUD:-sin respuesta}"
echo
echo "Listo: http://${SERVIDOR}/  (versión $(git rev-parse --short HEAD))"
echo "Credenciales en $CREDENCIALES (solo root)."
echo "Para actualizar más adelante: bash $APP_DIR/scripts/desplegar.sh main"
