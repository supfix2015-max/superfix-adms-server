<?php

namespace Tests\Feature;

use App\Http\Middleware\AdmsPilotGate;
use Illuminate\Http\Request;
use Tests\TestCase;

class SuperfixSecurityGateTest extends TestCase
{
    public function test_anonymous_admin_route_redirects_to_login(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/login');
    }

    public function test_adms_device_requests_are_denied_by_default(): void
    {
        config()->set('adms_pilot.enabled', false);
        $this->get('/iclock/cdata?SN=TEST-UA300-01')->assertForbidden();
        $this->get('/iclock/getrequest?SN=TEST-UA300-01')->assertForbidden();
    }

    public function test_public_registration_and_anonymous_device_registration_are_not_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/api/device/register', [])->assertNotFound();
    }

    public function test_pilot_accepts_allowlisted_serial_from_different_networks_without_ip_restriction(): void
    {
        config()->set('adms_pilot.enabled', true);
        config()->set('adms_pilot.serials', ['TEST-UA300-01']);
        config()->set('adms_pilot.ips', []);

        $gate = new AdmsPilotGate();
        foreach (['198.51.100.11', '203.0.113.22'] as $ip) {
            $request = Request::create('/iclock/getrequest?SN=TEST-UA300-01', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
            $result = $gate->handle($request, fn () => response('OK'));
            $this->assertSame(200, $result->getStatusCode());
        }
    }

    public function test_pilot_rejects_unknown_serial_even_without_ip_restriction(): void
    {
        config()->set('adms_pilot.enabled', true);
        config()->set('adms_pilot.serials', ['TEST-UA300-01']);
        config()->set('adms_pilot.ips', []);

        $gate = new AdmsPilotGate();
        $request = Request::create('/iclock/getrequest?SN=UNREGISTERED', 'GET');
        $this->assertSame(403, $gate->handle($request, fn () => response('OK'))->getStatusCode());
    }
}
