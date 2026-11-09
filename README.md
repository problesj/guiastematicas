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
- Datos de ejemplo (solo desarrollo): `docker compose exec app php bin/console doctrine:fixtures:load`.
  Crea departamentos, materias y cinco cuentas (`admin@ucn.cl`, `editora1@ucn.cl`, ...) con la contraseña `guias-desarrollo`.

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

6. Crear el primer administrador (pide la contraseña de forma oculta):
   `sudo -u www-data php bin/console app:crear-administrador correo@ucn.cl "Nombre" "Apellidos"`

El VirtualHost de Apache está en [deploy/apache/guias.conf](deploy/apache/guias.conf).

## Inicio de sesión del personal

El personal entra en `/es/acceso` (o `/en/acceso`). Solo pueden entrar personas creadas antes por un administrador
en **Panel → Usuarios**; nadie se registra solo. Cada persona puede tener uno o más métodos:

| Método | Cómo se activa | Variables en `.env.local` |
| --- | --- | --- |
| Contraseña propia | El administrador le asigna una contraseña (mínimo 12 caracteres) | ninguna |
| LDAP UCN | El administrador anota su usuario LDAP; entra con ese usuario y la contraseña institucional | `LDAP_URL`, `LDAP_CIFRADO` y `LDAP_DN_USUARIO`, o bien `LDAP_BASE_DN`, `LDAP_FILTRO`, `LDAP_BIND_DN`, `LDAP_BIND_PASSWORD` |
| Google | El correo de su cuenta de Google coincide con el registrado | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` y, opcional, `GOOGLE_DOMINIO=ucn.cl` |

Mientras `LDAP_URL` o `GOOGLE_CLIENT_ID` estén vacías, ese método queda apagado y no aparece en el formulario.
Para Google, crear un "ID de cliente OAuth" de tipo aplicación web en Google Cloud Console con la URI de redirección
`https://<dominio>/conectar/google/verificar`. El ejemplo de cada variable está en [.env](.env).

Roles: **Administrador** (usuarios, departamentos, materias y todo el sitio), **Editor** (crea y publica sus guías)
y **Colaborador** (edita las guías que le compartan). Las guías llegan en la fase 4.
