<?php

namespace Tests\Feature;

use App\Http\Middleware\AdmsInstanceRoleGate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AdmsDualHostGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('adms_instance.role', 'dual');
        config()->set('adms_instance.device_host', 'at1.superfix.ae');
        config()->set('adms_instance.console_host', 'at2.superfix.ae');
    }

    private function gate(string $url): Response
    {
        $request = Request::create($url, 'GET');
        return (new AdmsInstanceRoleGate())->handle($request, static fn (): Response =>
            response('ALLOWED', Response::HTTP_OK)
        );
    }

    public function test_http_at1_accepts_device_getrequest(): void
    {
        $this->assertSame(200, $this->gate('http://at1.superfix.ae/iclock/getrequest?SN=TEST')->getStatusCode());
    }

    public function test_http_at1_rejects_admin_and_oauth(): void
    {
        $this->assertSame(404, $this->gate('http://at1.superfix.ae/admin/dashboard')->getStatusCode());
        $this->assertSame(404, $this->gate('http://at1.superfix.ae/admin/integrations/zenhr')->getStatusCode());
    }

    public function test_https_at2_allows_console_but_rejects_device_protocol(): void
    {
        $this->assertSame(200, $this->gate('https://at2.superfix.ae/admin/dashboard')->getStatusCode());
        $this->assertSame(404, $this->gate('https://at2.superfix.ae/iclock/cdata')->getStatusCode());
        $this->assertSame(404, $this->gate('https://at2.superfix.ae/api/device/ping')->getStatusCode());
    }

    public function test_unknown_host_is_rejected(): void
    {
        $this->assertSame(404, $this->gate('https://unknown.example.com/admin/dashboard')->getStatusCode());
    }

    public function test_local_container_health_probe_is_allowed(): void
    {
        $this->assertSame(200, $this->gate('http://127.0.0.1:8080/api/health')->getStatusCode());
    }
}
