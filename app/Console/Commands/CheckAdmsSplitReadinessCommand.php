<?php

namespace App\Console\Commands;

use App\Services\ZenHrAttendanceOutbox;
use Illuminate\Console\Command;

final class CheckAdmsSplitReadinessCommand extends Command
{
    protected $signature = 'adms:split-readiness';
    protected $description = 'Read-only check of AT1 gateway / AT2 console role and attendance routing configuration';

    public function handle(ZenHrAttendanceOutbox $outbox): int
    {
        $role = (string) config('adms_instance.role');
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $expected = $role === 'devices' ? 'at1.superfix.ae'
            : ($role === 'console' ? 'at2.superfix.ae' : '');
        $hostOkay = $expected !== '' && strcasecmp($host, $expected) === 0;
        $scheme = (string) parse_url((string) config('app.url'), PHP_URL_SCHEME);
        $schemeOkay = ($role === 'devices' && $scheme === 'http')
            || ($role === 'console' && $scheme === 'https');

        $rows = [
            ['Instance role', $role],
            ['Configured APP_URL host', $host ?: 'MISSING'],
            ['Expected role host', $expected ?: 'AT1/AT2 ROLE NOT ENABLED'],
            ['Host / scheme', $hostOkay && $schemeOkay ? 'OK' : 'BLOCKER'],
            ['Physical device ingress', $role === 'devices' ? 'AT1 HTTP ON' :
                ($role === 'console' ? 'AT2 BLOCKED' : 'Not yet separated')],
            ['Admin console', $role === 'console' ? 'AT2 HTTPS ON' :
                ($role === 'devices' ? 'AT1 BLOCKED' : 'Not yet separated')],
            ['ZenHR OAuth', config('zenhr.enabled') ? 'ENABLED' : 'DISABLED'],
            ['ZenHR outbox collector configured', config('zenhr.outbox_auto_collect') ? 'YES' : 'NO'],
        ];

        if ($role === 'devices') {
            $rows[] = ['Original SQL Hili writer', config('legacy_attendance.attendance_write_enabled')
                && in_array('CEG9223661162', config('legacy_attendance.attendance_write_serials', []), true)
                ? 'APPROVED HILI ONLY' : 'Review original Hili writer allowlist'];
            $rows[] = ['ZenHR vendor operations at AT1', ! config('zenhr.enabled')
                ? 'OFF · correct' : 'BLOCKER · remove ZenHR credentials/flag'];
        } elseif ($role === 'console') {
            $rows[] = ['ZenHR outbox SQL', $outbox->isProvisioned()
                ? 'READY' : 'DBA PROVISIONING REQUIRED'];
            $rows[] = ['Legacy original attendance writer', config('legacy_attendance.attendance_write_enabled')
                ? 'BLOCKER · disable at AT2' : 'OFF · correct'];
        }

        $rows[] = ['ZenHR external attendance POST', 'NOT IMPLEMENTED · NO DELIVERY'];
        $this->table(['AT1/AT2 safety check', 'Status'], $rows);
        $this->warn('Read-only command. No device polling, attendance writes or ZenHR network calls.');

        $fail = ! $hostOkay || ! $schemeOkay || ! in_array($role, ['devices', 'console'], true);
        if ($role === 'devices') {
            $fail = $fail || (bool) config('zenhr.enabled');
        } elseif ($role === 'console') {
            $fail = $fail || (bool) config('legacy_attendance.attendance_write_enabled');
        }
        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
