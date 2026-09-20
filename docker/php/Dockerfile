FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev ca-certificates curl cron tzdata \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod headers rewrite

COPY php.ini /usr/local/etc/php/conf.d/mtg.ini
COPY sitemap.conf /etc/apache2/conf-enabled/sitemap.conf

# Rotina automática do acervo: o entrypoint monta o cron a partir de AUTO_UPDATE_* e TZ.
ENV TZ=America/Sao_Paulo
COPY entrypoint.sh /usr/local/bin/deckarium-entrypoint
RUN chmod +x /usr/local/bin/deckarium-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["deckarium-entrypoint"]
CMD ["apache2-foreground"]
