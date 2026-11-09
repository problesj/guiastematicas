#!/usr/bin/env bash
# Prepara un servidor Ubuntu 26.04 LTS limpio para Guías temáticas.
# Sirve igual para la máquina virtual de pruebas y para el servidor de producción de la UCN.
# Uso (como root): DB_PASSWORD='...' bash scripts/instalar-servidor.sh
set -euo pipefail

: "${DB_PASSWORD:?Define DB_PASSWORD con la contraseña del usuario de base de datos}"
DB_NAME="${DB_NAME:-guias}"
DB_USER="${DB_USER:-guias}"
APP_DIR="${APP_DIR:-/var/www/guias}"

apt-get update
apt-get install -y apache2 mysql-server composer git unzip certbot python3-certbot-apache ufw fail2ban unattended-upgrades \
    php8.5-fpm php8.5-cli php8.5-mysql php8.5-intl php8.5-mbstring php8.5-xml php8.5-zip php8.5-curl \
    php8.5-gd php8.5-ldap php-apcu php-imagick

# Apache con PHP-FPM
a2enmod proxy_fcgi setenvif rewrite headers ssl
a2enconf php8.5-fpm
cp "$(dirname "$0")/../deploy/apache/guias.conf" /etc/apache2/sites-available/guias.conf
a2dissite 000-default || true
a2ensite guias

# MySQL: solo escucha en localhost (valor por defecto en Ubuntu); base y usuario de la aplicación.
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# Cortafuegos: SSH, HTTP y HTTPS.
ufw allow OpenSSH
ufw allow 'Apache Full'
ufw --force enable

# El código pertenece a root; desplegar.sh da a www-data solo var/ y public/assets.
install -d "$APP_DIR"
systemctl reload apache2

echo "Servidor listo. Siguiente paso: clonar el repositorio en $APP_DIR, crear .env.local y ejecutar scripts/desplegar.sh"
echo "En producción, con el dominio apuntando al servidor: certbot --apache -d <dominio>"
