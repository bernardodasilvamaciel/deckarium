FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev ca-certificates curl \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod headers rewrite

COPY php.ini /usr/local/etc/php/conf.d/mtg.ini
COPY sitemap.conf /etc/apache2/conf-enabled/sitemap.conf
WORKDIR /var/www/html
