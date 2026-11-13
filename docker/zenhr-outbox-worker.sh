#!/bin/sh
# AT2-only scanner: stages new SQL attendance into adms outbox once/minute.
# It never issues an outbound ZenHR API POST.
set -u
cd /var/www/html
while :; do
    /usr/local/bin/php artisan adms:zenhr-outbox --apply --limit=500
    sleep 60
done
