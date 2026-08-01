FROM composer:2 AS build

WORKDIR /app
COPY . /app

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --ignore-platform-req=ext-gd \
    --optimize-autoloader

FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        default-mysql-client \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd intl opcache pdo_mysql zip \
    && a2enmod expires headers rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/web

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php-cloud-run.ini /usr/local/etc/php/conf.d/99-cloud-run.ini
COPY docker/entrypoint.sh /usr/local/bin/drupal-entrypoint
COPY --from=build --chown=www-data:www-data /app /var/www/html

RUN chmod +x /usr/local/bin/drupal-entrypoint \
    && mkdir -p /var/www/html/web/sites/default/files \
    && chown -R www-data:www-data /var/www/html/web/sites/default

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["drupal-entrypoint"]
CMD ["apache2-foreground"]
