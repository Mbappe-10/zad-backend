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
        opcache \
    && rm -rf /var/lib/apt/lists/*

# ZAD_SPEED: compile PHP once and keep it in memory (the code only changes on deploy).
RUN printf '%s\n' \
    'opcache.enable=1' \
    'opcache.enable_cli=1' \
    'opcache.memory_consumption=192' \
    'opcache.interned_strings_buffer=16' \
    'opcache.max_accelerated_files=30000' \
    'opcache.validate_timestamps=0' \
    'realpath_cache_size=4096K' \
    'realpath_cache_ttl=600' \
    > /usr/local/etc/php/conf.d/zad-opcache.ini

# ZAD_WEBP_CHECK
RUN php -r "if (!function_exists('imagewebp')) { fwrite(STDERR, 'ERROR: imagewebp missing\n'); exit(1); } echo 'PASS: imagewebp enabled\n';"


COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Requests served at the same time (menu screens, cashiers and stations poll);
# 2 made every click wait behind the polls.
ENV PHP_CLI_SERVER_WORKERS=8

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

CMD set -e; php artisan optimize:clear; if [ "${ZAD_RUN_MIGRATIONS:-false}" = "true" ]; then php artisan migrate --force; fi; php artisan zad:db-diagnose || true; php artisan schedule:work >> storage/logs/scheduler.log 2>&1 & exec php artisan serve --no-reload --host=0.0.0.0 --port=${PORT:-10000}
