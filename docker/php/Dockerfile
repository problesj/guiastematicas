# Imagen de desarrollo: Apache 2.4 + PHP 8.5, las mismas versiones que trae Ubuntu 26.04 LTS.
# Basada en la idea de sp5-docker (Apache con DocumentRoot en public/ y FallbackResource),
# pero con la imagen oficial de PHP en lugar de la de Azure App Service.
FROM php:8.5-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libldap2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl pdo_mysql zip gd ldap \
    && pecl install apcu && docker-php-ext-enable apcu \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-guias.ini
COPY docker/apache/guias.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/guias
