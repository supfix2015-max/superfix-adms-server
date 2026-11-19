#!/bin/sh
# AT2: stage new punches, optionally send one ZenHR bulk POST per cycle (15 punches, 30-second normal delay).
# ZENHR_ATTENDANCE_AUTO_SEND is an independent opt-in.
set -u
cd /var/www/html
sender_halted=0

while :; do
    # Original dbo.Attendance is authoritative. This reconciles only valid
    # staged punches, with an independent explicit opt-in and SQL INSERT-only
    # login. It NEVER updates/deletes existing original attendance.
    if [ "${LEGACY_SQLSRV_ATTENDANCE_PROMOTER_ENABLED:-false}" = "true" ]; then
        if ! /usr/local/bin/php artisan adms:attendance-promote --apply --limit=250; then
            echo "Original attendance promotion failed; check SQL permissions/clock mismatches." >&2
            sleep 60
            continue
        fi
    fi

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
        if ! /usr/local/bin/php artisan adms:zenhr-auto-send --limit=15; then
            echo "ZenHR sender HALTED after error; stage-only until redeploy." >&2
            sender_halted=1
        fi
    fi

    sleep 30
done
