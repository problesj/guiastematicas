#!/usr/bin/env bash
# Respaldo diario de la base de datos y de los archivos subidos. Programar con cron, por ejemplo:
#   30 2 * * * root /var/www/guias/scripts/respaldar.sh
# Conserva 30 días. Copiar además DESTINO a otro equipo (fuera del servidor).
set -euo pipefail

DB_NAME="${DB_NAME:-guias}"
APP_DIR="${APP_DIR:-/var/www/guias}"
DESTINO="${DESTINO:-/var/backups/guias}"
FECHA="$(date +%Y-%m-%d_%H%M)"

install -d -m 700 "$DESTINO"
mysqldump --single-transaction --routines "$DB_NAME" | gzip > "$DESTINO/bd_$FECHA.sql.gz"
if [[ -d "$APP_DIR/public/uploads" ]]; then
    tar -czf "$DESTINO/archivos_$FECHA.tar.gz" -C "$APP_DIR/public" uploads
fi
find "$DESTINO" -type f -mtime +30 -delete
echo "Respaldo guardado en $DESTINO ($FECHA)"
