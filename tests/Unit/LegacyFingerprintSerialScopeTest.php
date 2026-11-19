<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Services\FingerprintReviewService;
use Illuminate\Http\Request;
use Tests\TestCase;

final class LegacyFingerprintSerialScopeTest extends TestCase
{
    public function test_legacy_serial_mode_is_disabled_by_default(): void
    {
        config(['zkteco.fingerprint_review_dispatch'=>true,'zkteco.fingerprint_review_serial_only'=>false,
            'zkteco.fingerprint_review_trusted_sources'=>[],'zkteco.fingerprint_review_serial_only_serials'=>['TEST9001']]);
        $device=new Device(['serial_number'=>'TEST9001']);
        $request=Request::create('http://at1.superfix.ae/iclock/getrequest?SN=TEST9001');
        $this->assertNull((new FingerprintReviewService())->authorizationMode($request,$device,false));
    }

    public function test_serial_exception_is_limited_to_explicit_device_host_and_paths(): void
    {
        config(['zkteco.fingerprint_review_serial_only_serials'=>['TEST9001'],
            'zkteco.token_query_key'=>'token']);
        $service=new FingerprintReviewService();
        $device=new Device(['serial_number'=>'TEST9001']);
        $method=new \ReflectionMethod($service,'serialOnlyRequestMatches');
        $method->setAccessible(true);
        $check=fn(string $url)=>$method->invoke($service,Request::create($url),$device);
        $this->assertTrue($check('http://at1.superfix.ae/iclock/getrequest?SN=TEST9001'));
        $this->assertTrue($check('http://at1.superfix.ae/iclock/devicecmd?SN=TEST9001'));
        $this->assertFalse($check('http://at1.superfix.ae/iclock/cdata?SN=TEST9001'));
        $this->assertFalse($check('http://at2.superfix.ae/iclock/getrequest?SN=TEST9001'));
        $this->assertFalse($check('http://at1.superfix.ae/iclock/getrequest?SN=OTHER'));
        $this->assertFalse($check('http://at1.superfix.ae/iclock/getrequest?SN=TEST9001&token=wrong'));
        $this->assertFalse($check('http://at1.superfix.ae/iclock/getrequest?SN=TEST9001&sn=OTHER'));
        config(['zkteco.fingerprint_review_serial_only_serials'=>[]]);
        $this->assertFalse($check('http://at1.superfix.ae/iclock/getrequest?SN=TEST9001'));
    }
}
