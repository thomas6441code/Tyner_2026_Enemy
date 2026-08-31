FROM node:20-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY vite.config.js tsconfig.json ./
RUN npm run build


FROM php:8.4-fpm-alpine

# System dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    mysql-client \
    git \
    curl \
    zip \
    unzip \
    icu-libs \
    libpng \
    libjpeg-turbo \
    freetype \
    && apk add --no-cache --virtual .build-deps \
    icu-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    && docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    && docker-php-ext-install \
    pdo_mysql \
    bcmath \
    opcache \
    intl \
    gd \
    && apk del .build-deps


# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html


# PHP dependencies
COPY composer.json composer.lock ./

# Prefer Git/source repositories instead of GitHub dist ZIPs
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-source \
    --optimize-autoloader


# Application
COPY . .

COPY --from=frontend /app/public/build ./public/build


# Laravel writable directories
RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache


# Nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf


# Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf


# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh


EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
