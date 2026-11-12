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
     gnupg ca-certificates unixodbc-dev libgssapi-krb5-2 $PHPIZE_DEPS \
     libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libicu-dev \
     libonig-dev libxml2-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j"$(nproc)" mbstring zip gd bcmath intl pcntl opcache \
  && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
  && DEB_ARCH="$(dpkg --print-architecture)" \
  && echo "deb [arch=${DEB_ARCH} signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/microsoft-prod.list \
  && apt-get update \
  && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
  && pecl install sqlsrv \
  && pecl install pdo_sqlsrv \
  && docker-php-ext-enable sqlsrv pdo_sqlsrv \
  && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
COPY --from=frontend /src/public/build ./public/build
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
  && composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader \
  && php -l routes/web.php && php -l routes/device.php && php -l app/Http/Middleware/AdmsPilotGate.php \
  && php -l app/Console/Commands/InspectLegacySqlServerCommand.php \
  && php -l app/Console/Commands/AuditLegacyAttendanceCommand.php \
  && php -l app/Http/Controllers/Admin/LegacyAttendanceController.php \
  && php -l app/Console/Commands/InspectLegacyAttendanceWriteReadinessCommand.php \
  && php -l app/Services/LegacyAttendanceWriter.php \
  && php -l app/Services/LegacyAttendanceValueMapper.php \
  && php -l app/Services/LegacyDashboardService.php \
  && php -l config/legacy_attendance.php \
  && php -l config/database.php \
  && chown -R www-data:www-data storage bootstrap/cache
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/superfix-adms.conf
COPY docker/entrypoint.sh /usr/local/bin/superfix-entrypoint
RUN chmod 0755 /usr/local/bin/superfix-entrypoint && nginx -t
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 CMD curl -fsS -o /dev/null http://127.0.0.1:8080/api/health || exit 1
ENTRYPOINT ["/usr/local/bin/superfix-entrypoint"]
CMD ["/usr/bin/supervisord","-n","-c","/etc/supervisor/supervisord.conf"]
