# Dev/verification PHP image. Not part of the fixture app (the app stays Docker-free).
# Build another PHP line with: PHP_VERSION=8.2 docker compose build php-dev
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libzip-dev \
 && docker-php-ext-install pdo_mysql zip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dev only: no memory cap, so peak-memory measurements at large N do not die on the CLI default (128M).
RUN echo "memory_limit=-1" > /usr/local/etc/php/conf.d/zz-dev.ini

WORKDIR /app
