<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeZone;

class SystemClockService
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->resolveTimezone());
    }

    public function formatForDevice(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    /**
     * PUSH SDK /iclock/cdata?type=time response: ISO local time with
     * explicit numeric UTC offset, e.g. 2026-11-12T16:39:00+04:00.
     * No conversion of existing punch records is involved.
     */
    public function formatForPushTimeResponse(): string
    {
        return $this->now()->format('Y-m-d\\TH:i:sP');
    }

    /**
     * ZKTeco PUSH SDK Appendix 5 OldEncodeTime (NOT Unix timestamp).
     * Clock is encoded as a local wall clock at the server's configured
     * device time zone, e.g. Asia/Dubai (UTC+04:00).
     */
    public function encodeForPushCommand(): int
    {
        $now = $this->now();
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $day = (int) $now->format('j');
        $hour = (int) $now->format('G');
        $minute = (int) $now->format('i');
        $second = (int) $now->format('s');

        return ((($year - 2000) * 12 * 31 + ($month - 1) * 31 + ($day - 1)) * 86400)
            + (($hour * 60 + $minute) * 60) + $second;
    }

    /**
     * Firmware-variant reply after SET OPTIONS DateTime command:
     * DateTime=<OldEncodeTime>,ServerTZ=+0400
     */
    public function formatForRealtimeTimeResponse(): string
    {
        return 'DateTime='.$this->encodeForPushCommand()
            .',ServerTZ='.$this->now()->format('O');
    }

    public function normalizeForDevice(mixed $value): string
    {
        if ($value === null || $value === '') {
            return $this->formatForDevice();
        }

        return CarbonImmutable::parse((string) $value, $this->resolveTimezone())
            ->setTimezone($this->resolveTimezone())
            ->format('Y-m-d H:i:s');
    }

    public function timezoneName(): string
    {
        return $this->resolveTimezone()->getName();
    }

    public function deviceTimezoneOption(): string
    {
        // PUSH SDK defines TimeZone as a signed offset, e.g. +04:00.
        // Numeric "4" is not the expected wire format.
        return $this->now()->format('P');
    }

    private function resolveTimezone(): DateTimeZone
    {
        foreach ($this->timezoneCandidates() as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                return new DateTimeZone(trim($candidate));
            } catch (\Throwable) {
            }
        }

        return new DateTimeZone('UTC');
    }

    private function timezoneCandidates(): array
    {
        return array_filter([
            config('zkteco.system_timezone'),
            getenv('TZ') ?: null,
            $this->readEtcTimezone(),
            $this->readLocaltimeSymlinkTimezone(),
            config('app.timezone'),
        ]);
    }

    private function readEtcTimezone(): ?string
    {
        $path = '/etc/timezone';
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $value = trim((string) @file_get_contents($path));

        return $value !== '' ? $value : null;
    }

    private function readLocaltimeSymlinkTimezone(): ?string
    {
        $target = @readlink('/etc/localtime');
        if (! is_string($target) || $target === '') {
            return null;
        }

        $needle = '/usr/share/zoneinfo/';
        $position = strpos($target, $needle);
        if ($position === false) {
            return null;
        }

        $timezone = substr($target, $position + strlen($needle));

        return $timezone !== '' ? $timezone : null;
    }
}
