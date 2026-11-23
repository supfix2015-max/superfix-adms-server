<?php

namespace Tests\Unit;

use App\Services\FingerprintReviewInventory;
use PHPUnit\Framework\TestCase;

final class FingerprintReviewInventoryTest extends TestCase
{
    private function row(int $id, string $employee, string $slot, string $hash, bool $valid = true): object
    {
        return (object)['id'=>$id,'employee_code'=>$employee,'template_index'=>$slot,
            'version_hash'=>$hash,'has_template'=>true,'is_valid'=>$valid];
    }

    private function job(int $source, string $employee, int $slot, string $hash, string $state): object
    {
        return (object)['source_biometric_id'=>$source,'employee_code'=>$employee,'finger_slot'=>$slot,
            'version_hash'=>$hash,'state'=>$state,'target_device_id'=>15];
    }

    public function test_matching_copies_do_not_enter_pending_review_again(): void
    {
        $a = str_repeat('A',64);
        $rows = collect([$this->row(680,'26458','6',$a),$this->row(4,'26458','6',$a),
            $this->row(5,'26459','6',$a),$this->row(6,'26458','7',$a)]);
        $result = FingerprintReviewInventory::classify($rows,collect([$this->job(4,'26458',6,$a,'unknown')]));
        $this->assertSame([680],$result['tabs']['matching']);
        $this->assertSame([4],$result['tabs']['sync']);
        $this->assertSame([4],$result['tabs']['investigation']);
        $this->assertEqualsCanonicalizing([5,6],$result['tabs']['pending']);
        $this->assertCount(4,$result['tabs']['all']);
    }

    public function test_unreviewed_duplicates_have_one_canonical_pending_record(): void
    {
        $a = str_repeat('A',64);
        $result = FingerprintReviewInventory::classify(collect([
            $this->row(9,'123','2',$a),$this->row(7,'123','2',$a)
        ]),collect());
        $this->assertSame([7],$result['tabs']['pending']);
        $this->assertSame([9],$result['tabs']['matching']);
    }

    public function test_conflicts_invalid_rows_and_prior_version_history_remain_visible(): void
    {
        $a = str_repeat('A',64);
        $b = str_repeat('B',64);
        $result = FingerprintReviewInventory::classify(collect([
            $this->row(1,'123','2',$a),$this->row(2,'123','2',$b),
            $this->row(3,'456','1',$a,false),$this->row(4,'789','3',$b),
        ]),collect([$this->job(4,'789',3,$a,'unknown')]));
        $this->assertSame([],$result['tabs']['pending']);
        $this->assertEqualsCanonicalizing([1,2,3,4],$result['tabs']['investigation']);
        $this->assertSame([4],$result['tabs']['sync']);
        $this->assertSame('version_conflict',$result['labels'][4]);
    }
}
