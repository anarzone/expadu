# Stage 1: Install PHP dependencies
FROM composer:2 AS composer-build

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .
RUN composer dump-autoload --optimize

# Stage 2: Build frontend assets (needs PHP for Wayfinder artisan command)
FROM php:8.4-cli-alpine AS node-build

# Install Node.js
RUN apk add --no-cache nodejs npm

# Install PHP extensions needed by artisan
RUN apk add --no-cache postgresql-dev icu-dev && \
    docker-php-ext-install pdo_pgsql intl bcmath

WORKDIR /app

# Copy PHP app + vendor from composer stage
COPY --from=composer-build /app /app

# Install npm dependencies and build
# Set APP_URL for Wayfinder route generation (must not be localhost)
RUN cp .env.example .env && \
    sed -i 's|APP_URL=.*|APP_URL=https://expadu.com|' .env && \
    php artisan key:generate --force
RUN npm ci --legacy-peer-deps
RUN npm run build

# Stage 3: Production image
FROM php:8.4-fpm-alpine AS production

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    postgresql-dev \
    libzip-dev \
    icu-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    oniguruma-dev \
    curl

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        zip \
        intl \
        gd \
        bcmath \
        pcntl \
        mbstring \
        opcache \
        calendar

# Install Redis extension
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Configure PHP for production
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/prod/php.ini "$PHP_INI_DIR/conf.d/99-app.ini"

WORKDIR /var/www/html

# Copy application code with vendor
COPY --from=composer-build /app/vendor ./vendor
COPY . .

# Copy built frontend assets
COPY --from=node-build /app/public/build ./public/build

# Copy Nginx and Supervisor configs
COPY docker/prod/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/prod/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create required directories and set permissions
RUN mkdir -p \
        /var/log/supervisor \
        storage/logs \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Copy entrypoint script
COPY docker/prod/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
