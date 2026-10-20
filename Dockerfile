FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install curl mbstring \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod headers

COPY docker/ports.conf /etc/apache2/ports.conf
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY public/ /var/www/html/public/
COPY src/ /var/www/html/src/

RUN chown -R root:root /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 8080
