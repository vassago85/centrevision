FROM php:8.3-fpm-alpine

# System packages: nginx + supervisor to run web, netcat to wait for the DB in
# the entrypoint, and the toolchain we need for composer + vite.
RUN apk add --no-cache \
    nginx \
    supervisor \
    git \
    curl \
    netcat-openbsd \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    postgresql-client \
    postgresql-dev \
    zip \
    unzip \
    oniguruma-dev \
    icu-dev \
    nodejs \
    npm \
    ca-certificates \
    tzdata

# Everything the app renders (schedules, logs, mailables) uses SAST.
ENV TZ=Africa/Johannesburg
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# install-php-extensions handles the build-dep dance and cleanup for us.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
    pdo_pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl \
    redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy source. Everything gitignored (vendor, node_modules, .env) is already
# excluded via .dockerignore so the build context stays small.
COPY . .

# PHP deps, then front-end build (Vite → /public/build), then bin the JS toolchain.
RUN composer install --no-dev --optimize-autoloader --no-interaction
RUN npm ci && npm run build && rm -rf node_modules

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

RUN mkdir -p /var/log/supervisor /run/nginx /var/log/php

COPY docker/entrypoint.sh /entrypoint.sh
# Belt-and-braces: strip any Windows line endings the shell script may have
# picked up on a dev machine before we mark it executable.
RUN sed -i 's/\r$//' /entrypoint.sh && chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
