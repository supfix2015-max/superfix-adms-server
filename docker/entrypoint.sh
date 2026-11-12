#!/bin/sh
set -eu

# This branch must never fall back to the old MySQL operational store.
# Fail closed before accepting HTTP or queue work if the SQL Server target is
# missing or accidentally points at the original historic attendance DB.
if [ "${DB_CONNECTION:-sqlsrv}" != "sqlsrv" ]; then
    echo "Refusing startup: DB_CONNECTION must be sqlsrv." >&2
    exit 1
fi
if [ -z "${ADMS_SQLSRV_HOST:-}" ] || [ -z "${ADMS_SQLSRV_DATABASE:-}" ] || [ -z "${ADMS_SQLSRV_USERNAME:-}" ] || [ -z "${ADMS_SQLSRV_PASSWORD:-}" ]; then
    echo "Refusing startup: dedicated ADMS_SQLSRV_* operational database credentials are required." >&2
    exit 1
fi
appdb=$(printf '%s' "${ADMS_SQLSRV_DATABASE}" | tr '[:upper:]' '[:lower:]')
historydb=$(printf '%s' "${LEGACY_SQLSRV_DATABASE:-Att2000_V4}" | tr '[:upper:]' '[:lower:]')
if [ "$appdb" = "$historydb" ]; then
    echo "Refusing startup: operational SQL database cannot be Att2000_V4." >&2
    exit 1
fi

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
