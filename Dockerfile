FROM php:8.4-cli

# ZAD_FINAL_PHOTO_UPLOAD_LIMITS
RUN printf '%s\n' \
    'upload_max_filesize=16M' \
    'post_max_size=20M' \
    'memory_limit=256M' \
    > /usr/local/etc/php/conf.d/zad-uploads.ini

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg62-turbo-dev libwebp-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        zip \
        mbstring \
        dom \
        simplexml \
        xml \
        xmlreader \
        xmlwriter \
        gd \
    && rm -rf /var/lib/apt/lists/*

# ZAD_WEBP_CHECK
RUN php -r "if (!function_exists('imagewebp')) { fwrite(STDERR, 'ERROR: imagewebp missing\n'); exit(1); } echo 'PASS: imagewebp enabled\n';"


COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Keep polling requests from blocking checkout or media uploads.
ENV PHP_CLI_SERVER_WORKERS=2

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .

RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && composer dump-autoload --optimize \
    && (php artisan storage:link || true) \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 10000

CMD ["/bin/sh", "-c", "set -e; php artisan optimize:clear; php artisan migrate --force; php artisan schedule:work >> storage/logs/scheduler.log 2>&1 & exec php artisan serve --no-reload --host=0.0.0.0 --port=${PORT:-10000}"]
