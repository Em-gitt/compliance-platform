FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl git unzip procps libpq-dev \
    && rm -rf /var/lib/apt/lists/*

COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql redis pcntl bcmath zip
RUN echo "upload_max_filesize=26M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size=27M" >> /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Capa de dependencias para aprovechar cache de Docker
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

# Copiar el código fuente completo
COPY . /var/www/html

# Crear directorios de framework, dar permisos y optimizar autoloader sin ejecutar scripts aún
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-scripts

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

EXPOSE 8000
CMD ["serve-and-work"]
