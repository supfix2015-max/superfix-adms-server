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
    echo "Refusing startup: operational SQL Server credentials are required." >&2
    exit 1
fi
appdb=$(printf '%s' "${ADMS_SQLSRV_DATABASE}" | tr '[:upper:]' '[:lower:]')
historydb=$(printf '%s' "${LEGACY_SQLSRV_DATABASE:-}" | tr '[:upper:]' '[:lower:]')
schema=$(printf '%s' "${ADMS_SQLSRV_SCHEMA:-}" | tr '[:upper:]' '[:lower:]')
if [ "$appdb" != "att2000_v4" ] || [ "$historydb" != "$appdb" ] || [ "$schema" != "adms" ]; then
    echo "Refusing startup: require Att2000_V4 as single DB, with isolated adms schema." >&2
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
# Explicit AT2-only opt-in for perpetual READ/STAGE of attendance after
# the DBA has provisioned the isolated outbox tables. Never auto-start AT1.
# Never sends anything to ZenHR until a separately verified adapter is enabled.
if { [ "${ADMS_INSTANCE_ROLE:-transition}" = "console" ] || [ "${ADMS_INSTANCE_ROLE:-transition}" = "dual" ]; } \
    && [ "${ZENHR_OUTBOX_AUTO_COLLECT:-false}" = "true" ]; then
    sed -i '/^\[program:zenhr-outbox\]/,$ s/^autostart=false$/autostart=true/' \
        /etc/supervisor/conf.d/superfix-adms.conf
fi
exec "$@"
