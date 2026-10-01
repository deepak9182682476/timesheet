FROM php:8.3-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libxslt1-dev \
        libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl gd zip xsl pdo_mysql opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/opt/kimai/public \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    APP_ENV=prod \
    APP_SECRET=build-secret-replaced-at-runtime \
    DATABASE_URL="mysql://timesheet:timesheet@db:3306/timesheet?charset=utf8mb4&serverVersion=11.4.9-MariaDB" \
    MAILER_URL=null://null \
    MAILER_FROM=timesheet@localhost \
    DEFAULT_URI=http://localhost:8001

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '\nServerName localhost\n<Directory /opt/kimai/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf

WORKDIR /opt/kimai

COPY docker/php.ini /usr/local/etc/php/conf.d/timesheet.ini
COPY . /opt/kimai

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && mkdir -p var/cache var/log var/data var/sessions var/plugins \
    && chown -R www-data:www-data var \
    && chmod +x docker/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/opt/kimai/docker/entrypoint.sh"]
