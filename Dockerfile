# ── Stage 1: PHP extension compiler ──────────────────────────────────────────
FROM php:8.4-fpm-alpine AS php-ext-build

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
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
        calendar \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# ── Stage 2: Composer dependencies ───────────────────────────────────────────
FROM composer:2 AS composer-build

RUN apk add --no-cache icu-dev && docker-php-ext-install intl

WORKDIR /app

# Lockfiles first → install layer caches unless composer.lock changes.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Source second (invalidates often, but composer install already done).
COPY . .
RUN composer dump-autoload --optimize

# ── Stage 3: Frontend assets (needs PHP for Wayfinder artisan command) ────────
FROM php:8.4-cli-alpine AS node-build

RUN apk add --no-cache nodejs npm postgresql-dev icu-dev \
    && docker-php-ext-install pdo_pgsql intl bcmath

WORKDIR /app

# npm install layer caches unless package-lock.json changes — by far the slowest
# step when source changed but lockfile didn't. Cache mount reuses npm's tarball
# cache across builds.
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --legacy-peer-deps

# Bring in PHP vendor (Wayfinder needs autoload to enumerate Laravel routes).
COPY --from=composer-build /app/vendor ./vendor
COPY composer.json composer.lock ./

# Source code last — frequent changes here invalidate only the build, not deps.
COPY . .

RUN cp .env.example .env \
    && sed -i 's|APP_URL=.*|APP_URL=https://expadu.com|' .env \
    && php artisan key:generate --force

# Frontend Sentry config is baked at BUILD time — Vite reads VITE_* from the
# process env when compiling. CI passes these as --build-arg per environment;
# empty defaults keep local `docker build` working with the browser side dark.
ARG VITE_SENTRY_DSN_PUBLIC=""
ARG VITE_SENTRY_ENVIRONMENT=""
ARG VITE_SENTRY_RELEASE=""
ENV VITE_SENTRY_DSN_PUBLIC=$VITE_SENTRY_DSN_PUBLIC \
    VITE_SENTRY_ENVIRONMENT=$VITE_SENTRY_ENVIRONMENT \
    VITE_SENTRY_RELEASE=$VITE_SENTRY_RELEASE

RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm run build && npm run build:ssr

# ── Stage 4: Production ───────────────────────────────────────────────────────
FROM php:8.4-fpm-alpine AS production

# Runtime libraries only — no -dev headers, no LLVM, no Python
RUN apk add --no-cache \
    nginx \
    supervisor \
    nodejs \
    libpq \
    libzip \
    icu-libs \
    freetype \
    libjpeg-turbo \
    libpng \
    oniguruma \
    curl

# Copy compiled extension .so files from build stage and enable them
COPY --from=php-ext-build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
RUN docker-php-ext-enable \
    pdo_pgsql pgsql zip intl gd bcmath pcntl mbstring opcache calendar redis

# Configure PHP for production
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/prod/php.ini "$PHP_INI_DIR/conf.d/99-app.ini"

WORKDIR /var/www/html

COPY --from=composer-build /app/vendor ./vendor
COPY . .
COPY --from=node-build /app/public/build ./public/build
COPY --from=node-build /app/public/sw.js ./public/sw.js
COPY --from=node-build /app/bootstrap/ssr ./bootstrap/ssr

COPY docker/prod/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/prod/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN mkdir -p \
        /var/log/supervisor \
        storage/logs \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/prod/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
