<?php

namespace Tests\Unit;

use App\Services\ZenHrPunchIdentity;
use PHPUnit\Framework\TestCase;

final class ZenHrPunchIdentityTest extends TestCase
{
    public function test_employee_number_and_exact_local_time_identify_event(): void
    {
        $key = ZenHrPunchIdentity::key('0017', '2026-11-13 08:20:01');
        $this->assertNotNull($key);
        $this->assertSame($key, ZenHrPunchIdentity::key('0017', '2026-11-13 08:20:01'));
        $this->assertNotSame($key, ZenHrPunchIdentity::key('17', '2026-11-13 08:20:01'));
        $this->assertNotSame($key, ZenHrPunchIdentity::key('0017', '2026-11-13 18:20:01'));
    }

    public function test_invalid_employee_or_timestamp_is_rejected(): void
    {
        $this->assertNull(ZenHrPunchIdentity::key('', '2026-11-13 08:20:01'));
        $this->assertNull(ZenHrPunchIdentity::key('[missing badge]', '2026-11-13 08:20:01'));
        $this->assertNull(ZenHrPunchIdentity::key('105', '2026-02-30 08:20:01'));
        $this->assertNull(ZenHrPunchIdentity::key('105', '2026-11-13 28:20:01'));
        $this->assertNull(ZenHrPunchIdentity::key('105', '2026-11-13 08:20'));
    }
}
