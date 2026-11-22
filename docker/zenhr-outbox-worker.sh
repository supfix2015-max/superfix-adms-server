#!/bin/sh
# AT2: stage new punches, optionally send one ZenHR bulk POST per cycle (15 punches, 30-second normal delay).
# ZENHR_ATTENDANCE_AUTO_SEND is an independent opt-in.
set -u
cd /var/www/html || exit 1

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

    # A transient error must not latch the worker off for its entire lifetime.
    # Every invocation independently checks durable SQL inflight/unknown/failed
    # states BEFORE selecting a batch. Never reset or retry those rows here.
    if [ "${ZENHR_ATTENDANCE_AUTO_SEND:-false}" = "true" ] &&
       [ "${ZENHR_ATTENDANCE_POST_ENABLED:-false}" = "true" ]; then
        if ! /usr/local/bin/php artisan adms:zenhr-auto-send --limit=15; then
            echo "ZenHR auto-send check failed; recheck durable safety guards in 120 seconds. No reset of delivery states." >&2
            sleep 120
            continue
        fi
    fi

    sleep 30
done
