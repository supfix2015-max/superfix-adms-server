<?php

namespace Tests\Unit;

use App\Services\ZenHrLegacyRequestType;
use PHPUnit\Framework\TestCase;

final class ZenHrLegacyRequestTypeTest extends TestCase
{
    public function test_nullable_status_id_uses_original_sql_check_in_or_out(): void
    {
        $this->assertSame(0, ZenHrLegacyRequestType::fromFields(null, 'Check-In'));
        $this->assertSame(1, ZenHrLegacyRequestType::fromFields(null, 'Check-Out'));
        $this->assertSame(0, ZenHrLegacyRequestType::fromFields('', 'check-in'));
        $this->assertSame(1, ZenHrLegacyRequestType::fromFields(null, 'CHECK-OUT'));
        $this->assertSame(0, ZenHrLegacyRequestType::fromFields(null, 'checkin'));
        $this->assertSame(1, ZenHrLegacyRequestType::fromFields(null, 'checkout'));
    }

    public function test_status_id_remains_authoritative_when_known(): void
    {
        $this->assertSame(0, ZenHrLegacyRequestType::fromFields('0', null));
        $this->assertSame(1, ZenHrLegacyRequestType::fromFields(1, 'Check-In'));
    }

    public function test_break_and_unknown_values_do_not_become_attendance_events(): void
    {
        $this->assertNull(ZenHrLegacyRequestType::fromFields(null, 'Break-Out'));
        $this->assertNull(ZenHrLegacyRequestType::fromFields(null, 'Overtime-In'));
        $this->assertNull(ZenHrLegacyRequestType::fromFields('2', 'Check-In'));
        $this->assertNull(ZenHrLegacyRequestType::fromFields(null, null));
        $this->assertNull(ZenHrLegacyRequestType::fromFields(null, ''));
        $this->assertNull(ZenHrLegacyRequestType::fromFields(null, ['Check-In']));
    }
}
