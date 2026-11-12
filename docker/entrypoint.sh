#!/bin/sh
set -eu

# Persistent storage may mount an empty storage/ folder.
mkdir -p /var/www/html/storage/app/public \
         /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Isolated test deployments must never consume copied database-backed device jobs.
# Existing production behavior remains unchanged.
if [ "${APP_ENV:-production}" = "staging" ] || [ "${APP_ENV:-production}" = "testing" ]; then
    sed -i '/^\[program:queue\]/,$ s/^autostart=true$/autostart=false/' \
        /etc/supervisor/conf.d/superfix-adms.conf
fi
exec "$@"
