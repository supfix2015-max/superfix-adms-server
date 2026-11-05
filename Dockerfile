# Superfix ADMS *pilot* build; NOT production-certified.
FROM node:22-bookworm-slim AS frontend
WORKDIR /src
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM php:8.3-fpm-bookworm
ENV APP_ENV=production APP_DEBUG=false COMPOSER_ALLOW_SUPERUSER=1
RUN apt-get update \
  && apt-get install -y --no-install-recommends nginx supervisor curl git unzip \
     libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libicu-dev \
     libonig-dev libxml2-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring zip gd bcmath intl pcntl opcache \
  && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
COPY --from=frontend /src/public/build ./public/build
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
  && composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader \
  && php -l routes/web.php && php -l routes/device.php && php -l app/Http/Middleware/AdmsPilotGate.php \
  && chown -R www-data:www-data storage bootstrap/cache
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/superfix-adms.conf
COPY docker/entrypoint.sh /usr/local/bin/superfix-entrypoint
RUN chmod 0755 /usr/local/bin/superfix-entrypoint && nginx -t
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 CMD curl -fsS -o /dev/null http://127.0.0.1:8080/api/health || exit 1
ENTRYPOINT ["/usr/local/bin/superfix-entrypoint"]
CMD ["/usr/bin/supervisord","-n","-c","/etc/supervisor/supervisord.conf"]
