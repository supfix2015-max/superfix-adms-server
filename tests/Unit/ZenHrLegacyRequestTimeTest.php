<?php

namespace Tests\Unit;

use App\Services\ZenHrLegacyRequestTime;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ZenHrLegacyRequestTimeTest extends TestCase
{
    public function test_vendor_receives_legacy_local_clock_without_utc_offset(): void
    {
        $this->assertSame(
            '2026-11-13 15:58:07',
            ZenHrLegacyRequestTime::fromSqlLocal('2026-11-13 15:58:07')
        );
        $this->assertSame(
            '2026-11-13 22:27:16',
            ZenHrLegacyRequestTime::fromSqlLocal('2026-11-13 22:27:16')
        );
    }

    public function test_invalid_dates_are_rejected_not_silently_normalized(): void
    {
        $this->expectException(RuntimeException::class);
        ZenHrLegacyRequestTime::fromSqlLocal('2026-02-31 15:58:07');
    }
}
