<?php

namespace Tests\Unit;

use App\Services\LegacyAttendanceValueMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LegacyAttendanceValueMapperTest extends TestCase
{
    public function test_preserves_checkin_checkout_and_both_overtime_directions(): void
    {
        $cases = [
            '0' => 'Check-In',
            '1' => 'Check-Out',
            '4' => 'Overtime-In',
            '5' => 'Overtime-Out',
        ];

        foreach ($cases as $deviceCode => $expected) {
            $this->assertSame($expected, LegacyAttendanceValueMapper::status([
                'log_type' => 'overtime',
                'raw_payload' => ['Status' => $deviceCode],
            ]));
        }
    }

    public function test_does_not_guess_an_ambiguous_overtime_state(): void
    {
        $this->expectException(RuntimeException::class);
        LegacyAttendanceValueMapper::status(['log_type' => 'overtime', 'raw_payload' => []]);
    }

    public function test_keeps_legacy_verification_code_two_and_fingerprint_label(): void
    {
        $this->assertSame('2', LegacyAttendanceValueMapper::verify([
            'verify_type' => 'rfid',
            'raw_payload' => ['Verify' => '2'],
        ]));
        $this->assertSame('FingerPrint', LegacyAttendanceValueMapper::verify([
            'verify_type' => 'fingerprint',
            'raw_payload' => ['Verify' => '1'],
        ]));
    }
}
