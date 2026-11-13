<?php

namespace Tests\Unit;

use App\Services\ZenHrRecordValues;
use PHPUnit\Framework\TestCase;

final class ZenHrRecordValuesTest extends TestCase
{
    public function test_nested_summary_fields_do_not_throw_or_coerce_into_strings(): void
    {
        $record = [
            'entry_time' => '08:01:00',
            'exit_time' => null,
            'first_in' => ['time' => '08:01:00'],
            'last_out' => ['time' => '17:02:00'],
        ];

        $this->assertSame(['08:01:00'], ZenHrRecordValues::summaryTimes($record));
        $this->assertSame(2, ZenHrRecordValues::structuredFieldCount($record, [
            'entry_time', 'exit_time', 'first_in', 'last_out',
        ]));
    }

    public function test_employee_number_is_used_when_card_id_is_nested(): void
    {
        $record = [
            'card_id' => ['code' => 'unknown'],
            'employee' => ['employment_number' => '00142'],
        ];
        $this->assertSame(['00142'], ZenHrRecordValues::identities($record));
    }

    public function test_numeric_card_id_and_employee_numbers_remain_distinct(): void
    {
        $record = [
            'card_id' => 142,
            'employee' => ['employment_number' => '00142'],
        ];
        $this->assertSame(['142', '00142'], ZenHrRecordValues::identities($record));
    }

    public function test_nested_and_boolean_values_are_not_used_as_employee_ids(): void
    {
        $record = [
            'card_id' => false,
            'employee' => ['employment_number' => ['value' => '12']],
        ];
        $this->assertSame([], ZenHrRecordValues::identities($record));
    }
}
