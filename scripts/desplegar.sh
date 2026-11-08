#!/usr/bin/env bash
# Despliega la versión actual de la rama indicada (por defecto main) en el servidor.
# Uso: bash scripts/desplegar.sh [rama]
# Requiere .env.local con APP_ENV=prod, APP_SECRET y DATABASE_URL (ver README).
set -euo pipefail

RAMA="${1:-main}"
APP_DIR="${APP_DIR:-/var/www/guias}"
cd "$APP_DIR"

if [[ ! -f .env.local ]]; then
    echo "Falta $APP_DIR/.env.local" >&2
    exit 1
fi

git fetch origin "$RAMA"
git checkout "$RAMA"
git reset --hard "origin/$RAMA"

export APP_ENV=prod
composer install --no-dev --optimize-autoloader --no-interaction
php bin/console importmap:install
php bin/console asset-map:compile
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console cache:clear
php bin/console cache:warmup

chown -R www-data:www-data var public/assets
systemctl reload php8.5-fpm

echo "Despliegue de $RAMA terminado: $(git rev-parse --short HEAD)"
