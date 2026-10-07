FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev unzip \
    && docker-php-ext-install curl mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

COPY --chown=www-data:www-data . /var/www/html
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p /var/www/html/var/uploads \
    && mkdir -p /var/lib/php/sessions \
    && chown -R www-data:www-data /var/www/html/var /var/lib/php/sessions

EXPOSE 80
