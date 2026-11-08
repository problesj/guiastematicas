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

## Pruebas

```bash
docker compose exec app php bin/phpunit
```

La integración continua (GitHub Actions) ejecuta en cada pull request las revisiones de sintaxis,
las migraciones contra MySQL 8.4, PHPUnit con PHP 8.5, `composer audit` y la construcción de la imagen Docker.

## Servidor (Ubuntu 26.04 LTS)

Sirve igual para la máquina virtual de pruebas y para el servidor de producción de la UCN.

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

El VirtualHost de Apache está en [deploy/apache/guias.conf](deploy/apache/guias.conf).
