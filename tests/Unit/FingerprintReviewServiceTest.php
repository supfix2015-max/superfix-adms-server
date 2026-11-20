<?php

namespace Tests\Unit;

use App\Models\DeviceCommand;
use App\Services\FingerprintReviewService;
use PHPUnit\Framework\TestCase;

final class FingerprintReviewServiceTest extends TestCase
{
    public function test_untrusted_direct_request_cannot_spoof_forwarded_address(): void
    {
        $this->assertSame('192.0.2.7', FingerprintReviewService::verifiedSourceIp('192.0.2.7','198.51.100.8',['10.0.0.2']));
    }

    public function test_verified_proxy_walk_uses_rightmost_untrusted_hop(): void
    {
        $this->assertSame('192.0.2.7', FingerprintReviewService::verifiedSourceIp('10.0.0.2','198.51.100.8, 192.0.2.7, 10.0.0.3',['10.0.0.2','10.0.0.3']));
        $this->assertNull(FingerprintReviewService::verifiedSourceIp('10.0.0.2','10.0.0.3',['10.0.0.2','10.0.0.3']));
        $this->assertNull(FingerprintReviewService::verifiedSourceIp('10.0.0.2','invalid',['10.0.0.2']));
    }

    public function test_fingerprint_wire_ids_do_not_include_clock_or_attlog_ranges(): void
    {
        $this->assertSame(1200000002, FingerprintReviewService::wireId(['ID'=>'1200000002']));
        $this->assertSame(1200000003, FingerprintReviewService::wireId(['CmdID'=>'1200000003']));
        foreach (['1','795000000','1200000000','1300000000','1795000000','abc'] as $id) {
            $this->assertNull(FingerprintReviewService::wireId(['ID'=>$id]));
        }
    }

    public function test_generic_fp_dispatch_is_held_but_face_and_attendance_are_not(): void
    {
        $this->assertTrue(FingerprintReviewService::isLegacyFingerprintPush(new DeviceCommand(['command'=>'SYNC_BIOMETRICS','parameters'=>['biometric_type'=>'fingerprint']])));
        $this->assertTrue(FingerprintReviewService::isLegacyFingerprintPush(new DeviceCommand(['command'=>'DATA UPDATE FINGERTMP'])));
        $this->assertFalse(FingerprintReviewService::isLegacyFingerprintPush(new DeviceCommand(['command'=>'SYNC_BIOMETRICS','parameters'=>['biometric_type'=>'face']])));
        $this->assertFalse(FingerprintReviewService::isLegacyFingerprintPush(new DeviceCommand(['command'=>'PULL_ATTENDANCE'])));
    }
}
