<?php

namespace Tests\Unit;

use App\Services\DeviceEmployeeRegistrationService as Registration;
use App\Services\ZKTecoPayloadParser;
use PHPUnit\Framework\TestCase;

final class DeviceEmployeeRegistrationIdentityTest extends TestCase
{
    public function test_registration_in_operlog_can_be_identified(): void
    {
        $parser = new ZKTecoPayloadParser();
        $records = $parser->parseUserPayload("USER PIN=26458\tName=Nithin\tPri=0\tCard=0")['records'];
        $this->assertCount(1,$records);
        $this->assertSame(['26458','Nithin'],Registration::identity($records[0],'OPERLOG'));
    }

    public function test_operation_and_biometric_records_are_not_registration_requests(): void
    {
        foreach (['OPLOG','FP PIN','FACE PIN','TMP','FID'] as $key) {
            $this->assertNull(Registration::identity(['employee_code'=>'26458','name'=>'Nithin',
                'raw'=>['PIN'=>'26458','Name'=>'Nithin',$key=>'1']],'OPERLOG'));
        }
        $this->assertNull(Registration::identity(['employee_code'=>'26458','name'=>'User 26458'],'USERINFO'));
        $this->assertNull(Registration::identity(['employee_code'=>'OPLOG1','name'=>'Nithin'],'USERINFO'));
    }

    public function test_names_do_not_silently_match_different_employees(): void
    {
        $this->assertTrue(Registration::sameName('Nithin',' nithin '));
        $this->assertFalse(Registration::sameName('Nithin','Dhiraj'));
        $this->assertFalse(Registration::sameName(null,'Nithin'));
    }
}
