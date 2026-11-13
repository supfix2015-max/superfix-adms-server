<?php

namespace Tests\Feature;

use App\Services\SystemClockService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ZktecoServerTimeTest extends TestCase
{
    public function test_official_old_encode_time_fixture_and_realtime_response(): void
    {
        config()->set('zkteco.system_timezone', 'Asia/Dubai');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2018-02-22 10:54:54', 'UTC'));
        try {
            $clock = app(SystemClockService::class);
            $this->assertSame(583080894, $clock->encodeForPushCommand());
            // Device command uses local UAE time, rtdata callback encodes UTC plus ServerTZ.
            $this->assertSame('DateTime=583066494,ServerTZ=+0400', $clock->formatForRealtimeTimeResponse());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_push_time_response_contains_dubai_clock_and_explicit_offset(): void
    {
        config()->set('zkteco.system_timezone', 'Asia/Dubai');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 12:39:00', 'UTC'));
        try {
            $clock = app(SystemClockService::class);

            $this->assertSame('2026-11-12T16:39:00+04:00', $clock->formatForPushTimeResponse());
            $this->assertSame('+04:00', $clock->deviceTimezoneOption());
            $this->assertSame('2026-11-12 16:39:00', $clock->formatForDevice());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
