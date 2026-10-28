# Mirrors the Rocky 10 droplet: PHP 8.3 with the same extensions, Apache with mod_rewrite.
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libpng-dev libjpeg-dev libwebp-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" intl mysqli pdo_mysql gd zip opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

# Apache runs as www-data; give it the host user's IDs so files it writes in the bind mount stay yours.
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g "${GID}" www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY vhost.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/zkm.ini

WORKDIR /var/www/html
