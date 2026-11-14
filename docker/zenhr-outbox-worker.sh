#!/bin/sh
# AT2: stage new punches, optionally send one ZenHR bulk POST per minute.
# ZENHR_ATTENDANCE_AUTO_SEND is an independent opt-in.
set -u
cd /var/www/html
sender_halted=0

while :; do
    if ! /usr/local/bin/php artisan adms:zenhr-outbox --apply --limit=500; then
        echo "ZenHR outbox staging failed; skip sending this cycle." >&2
        sleep 60
        continue
    fi

    # On error, the current worker halts sending until a deliberate restart.
    # SQL unknown/inflight states also block the next send.
    if [ "${ZENHR_ATTENDANCE_AUTO_SEND:-false}" = "true" ] &&
       [ "${ZENHR_ATTENDANCE_POST_ENABLED:-false}" = "true" ] &&
       [ "$sender_halted" -eq 0 ]; then
        if ! /usr/local/bin/php artisan adms:zenhr-auto-send --limit=10; then
            echo "ZenHR sender HALTED after error; stage-only until redeploy." >&2
            sender_halted=1
        fi
    fi

    sleep 60
done
