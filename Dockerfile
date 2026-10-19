FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_sqlite
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative
COPY . /var/www/html/
EXPOSE 80