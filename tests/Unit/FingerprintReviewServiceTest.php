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

    public function test_ack_denials_have_safe_reason_codes_without_changing_authorization(): void
    {
        $previous = \Illuminate\Container\Container::getInstance();
        $container = new \Illuminate\Container\Container();
        \Illuminate\Container\Container::setInstance($container);
        $config = new \Illuminate\Config\Repository([
            'zkteco' => [
                'fingerprint_review_dispatch' => true,
                'fingerprint_review_serial_only' => true,
                'fingerprint_review_serial_only_serials' => ['TEST001'],
                'fingerprint_review_trusted_sources' => [],
                'token_query_key' => 'token',
            ],
        ]);
        $container->instance('config', $config);
        try {
            $service = new FingerprintReviewService();
            $device = new \App\Models\Device();
            $device->forceFill(['serial_number' => 'TEST001']);
            $cases = [
                ['http://wrong.example/iclock/devicecmd?SN=TEST001', [], 'unexpected_host'],
                ['http://at1.superfix.ae/other?SN=TEST001', [], 'unexpected_path'],
                ['http://at1.superfix.ae/iclock/devicecmd', [], 'query_serial_missing_or_mismatch'],
                ['http://at1.superfix.ae/iclock/devicecmd?SN=TEST001&sn=OTHER', [], 'conflicting_query_serials'],
                ['http://at1.superfix.ae/iclock/devicecmd?SN=TEST001&token=', [], 'unverified_credentials_present'],
                ['http://at1.superfix.ae/iclock/devicecmd?SN=TEST001', ['HTTP_AUTHORIZATION' => 'Bearer invalid'], 'unverified_credentials_present'],
            ];
            foreach ($cases as [$url, $server, $expected]) {
                $request = \Illuminate\Http\Request::create($url, 'POST', [], [], [], $server);
                $reason = null;
                $this->assertNull($service->authorizationMode($request, $device, false, $reason));
                $this->assertSame($expected, $reason);
            }
            $request = \Illuminate\Http\Request::create('http://at1.superfix.ae/iclock/devicecmd?SN=TEST001', 'POST');
            $config->set('zkteco.fingerprint_review_serial_only_serials', []);
            $this->assertNull($service->authorizationMode($request, $device, false, $reason));
            $this->assertSame('serial_not_allowlisted', $reason);
            $this->assertSame('token', $service->authorizationMode($request, $device, true, $reason));
            $this->assertNull($reason);
            $config->set('zkteco.fingerprint_review_dispatch', false);
            $this->assertNull($service->authorizationMode($request, $device, true, $reason));
            $this->assertSame('dispatch_disabled', $reason);
        } finally {
            \Illuminate\Container\Container::setInstance($previous);
        }
    }
}
