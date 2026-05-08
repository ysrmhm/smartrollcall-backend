# SmartRollCall Laravel backend — Render.com free tier için Dockerfile
# PHP 8.3 + Composer + Laravel + PostgreSQL extension

FROM php:8.3-cli-alpine

# Build deps
RUN apk add --no-cache \
    bash \
    git \
    unzip \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    libzip-dev \
    icu-dev \
    postgresql-dev \
    autoconf \
    g++ \
    make

# PHP extensions
RUN docker-php-ext-configure gd \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        mbstring \
        bcmath \
        zip \
        gd \
        intl \
        exif \
        opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Composer dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Application code
COPY . .

# composer scripts (post-autoload-dump vb)
RUN composer dump-autoload --optimize --no-interaction

# Storage permissions
RUN mkdir -p storage/framework/{cache,sessions,views,testing} storage/logs bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

# Render free tier: $PORT env var ile başlatılır (genelde 10000)
ENV PORT=10000
EXPOSE 10000

# Start script — migrate hızlı, seed arka planda (port scan timeout olmasın)
COPY --chmod=755 docker-start.sh /app/docker-start.sh
CMD ["/app/docker-start.sh"]
