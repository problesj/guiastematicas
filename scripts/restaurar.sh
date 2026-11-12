#!/usr/bin/env bash
# Reemplaza los datos de este servidor por los de un respaldo hecho con scripts/respaldar.sh (por ejemplo, para pasar
# las guías del servidor de pruebas al piloto). Antes de borrar nada respalda los datos actuales en /var/backups/guias.
#
# Uso: sudo bash scripts/restaurar.sh bd_AAAA-MM-DD_HHMM.sql.gz [archivos_AAAA-MM-DD_HHMM.tar.gz]
#
# - Las cuentas de usuario pasan con el respaldo: después se entra con las contraseñas del servidor de origen.
# - Las claves guardadas cifradas en Administración → Integraciones no se pueden leer con el APP_SECRET de este
#   servidor, así que se borran: se usan las de .env.local o se vuelven a escribir en el panel.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Ejecutar como root: sudo bash $0 $*" >&2
    exit 1
fi
BD="${1:-}"
ARCHIVOS="${2:-}"
if [[ -z "$BD" || ! -f "$BD" ]]; then
    echo "Uso: sudo bash $0 bd_....sql.gz [archivos_....tar.gz]" >&2
    exit 1
fi
if [[ -n "$ARCHIVOS" && ! -f "$ARCHIVOS" ]]; then
    echo "No existe $ARCHIVOS" >&2
    exit 1
fi
gunzip -t "$BD"
[[ -z "$ARCHIVOS" ]] || tar -tzf "$ARCHIVOS" > /dev/null

DB_NAME="${DB_NAME:-guias}"
APP_DIR="${APP_DIR:-/var/www/guias}"
BD="$(realpath "$BD")"
[[ -z "$ARCHIVOS" ]] || ARCHIVOS="$(realpath "$ARCHIVOS")"

if [[ "${CONFIRMAR:-}" != "si" ]]; then
    read -r -p "Se reemplazarán la base de datos «$DB_NAME»${ARCHIVOS:+ y public/uploads} de este servidor. ¿Continuar? (si/no) " respuesta
    [[ "$respuesta" == "si" ]] || { echo "Cancelado."; exit 1; }
fi

echo "1/5 Respaldo de los datos actuales"
DESTINO="${DESTINO:-/var/backups/guias}" APP_DIR="$APP_DIR" DB_NAME="$DB_NAME" bash "$APP_DIR/scripts/respaldar.sh"

echo "2/5 Restaurando la base de datos"
mysql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c "$BD" | mysql "$DB_NAME"
mysql "$DB_NAME" -e "DELETE FROM ajuste WHERE cifrado = 1" 2>/dev/null || true

if [[ -n "$ARCHIVOS" ]]; then
    echo "3/5 Restaurando fotos e imágenes"
    rm -rf "$APP_DIR/public/uploads"
    tar -xzf "$ARCHIVOS" -C "$APP_DIR/public"
else
    echo "3/5 Sin archivo de imágenes: se mantienen las de este servidor"
fi

echo "4/5 Migraciones y caché"
cd "$APP_DIR"
export APP_ENV=prod GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$APP_DIR"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console cache:clear

echo "5/5 Permisos"
install -d public/uploads/personal public/uploads/guias public/uploads/sitio
chown -R www-data:www-data var public/uploads
systemctl reload php8.5-fpm

echo "Listo: datos restaurados desde $(basename "$BD"). El respaldo previo quedó en /var/backups/guias."
