FROM node:20-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY vite.config.js tsconfig.json ./
RUN npm run build

FROM php:8.2-fpm-alpine

# System dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    mysql-client \
    git \
    curl \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql bcmath opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP deps
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader

COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
