<?php

namespace Tests\Feature;

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
}
