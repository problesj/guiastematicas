#!/usr/bin/env bash
# Despliega la versión actual de la rama o etiqueta indicada (por defecto main) en el servidor.
# Uso: bash scripts/desplegar.sh [rama|etiqueta]
# Requiere .env.local con APP_ENV=prod, APP_SECRET y DATABASE_URL (ver README).
set -euo pipefail

RAMA="${1:-main}"
if [[ $EUID -ne 0 ]]; then
    echo "Ejecutar como root: sudo bash $0 $RAMA" >&2
    exit 1
fi
APP_DIR="${APP_DIR:-/var/www/guias}"
cd "$APP_DIR"

# Si el directorio pertenece a otro usuario (p. ej. www-data), git rechaza operar sin esta excepción.
# Se pasa por entorno para no depender de HOME ni modificar la configuración global.
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$APP_DIR"

if [[ ! -f .env.local ]]; then
    echo "Falta $APP_DIR/.env.local" >&2
    exit 1
fi

git fetch --tags --force origin
git fetch origin "$RAMA" 2>/dev/null || true
if git show-ref --verify --quiet "refs/remotes/origin/$RAMA"; then
    git checkout -B "$RAMA" "origin/$RAMA"
    git reset --hard "origin/$RAMA"
elif git show-ref --verify --quiet "refs/tags/$RAMA"; then
    git checkout --detach "refs/tags/$RAMA"
else
    echo "No existe la rama ni la etiqueta $RAMA en origin" >&2
    exit 1
fi

export APP_ENV=prod
# Como root, Composer desactiva sus plugins (symfony/flex) si no se le autoriza, y entonces falla "symfony-cmd".
if [[ $EUID -eq 0 ]]; then
    export COMPOSER_ALLOW_SUPERUSER=1
fi
composer install --no-dev --optimize-autoloader --no-interaction
php bin/console importmap:install
php bin/console asset-map:compile
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console cache:clear
php bin/console cache:warmup

# Fotos del personal (se respaldan con scripts/respaldar.sh).
install -d public/uploads/personal public/uploads/guias
chown -R www-data:www-data var public/assets public/uploads
systemctl reload php8.5-fpm

echo "Despliegue de $RAMA terminado: $(git rev-parse --short HEAD)"
