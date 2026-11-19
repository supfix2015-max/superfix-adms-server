<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeviceEmployeeRegistrationService
{
    public const TABLE = 'adms.employee_registration_requests';

    public function ready(): bool
    {
        return DB::connection('sqlsrv')->selectOne("SELECT OBJECT_ID(N'adms.employee_registration_requests',N'U') AS object_id")?->object_id !== null;
    }

    public function rows()
    {
        return DB::connection('sqlsrv')->table(self::TABLE);
    }

    private function registered(Device $device): bool
    {
        return !$device->trashed() && DB::connection('legacy_sqlsrv')->table('dbo.Device')
            ->where('DeviceSerialNumber',$device->serial_number)->exists();
    }

    public static function identity(array $record, string $table): ?array
    {
        $code = trim((string)($record['employee_code'] ?? ''));
        $name = trim((string)($record['name'] ?? ''));
        if (!preg_match('/^\d{1,32}$/D',$code) || $name === '' || mb_strlen($name)>160
            || preg_match('/[\x00-\x1F\x7F]/u',$name) || preg_match('/^User\s+(?:\d+|OPLOG)\b/i',$name)) return null;
        if ($table === 'OPERLOG') {
            $raw = array_change_key_case((array)($record['raw'] ?? []), CASE_LOWER);
            if (!isset($raw['name']) || (!isset($raw['user pin']) && !isset($raw['pin']))) return null;
            foreach (array_keys($raw) as $key) {
                if (preg_match('/^(oplog|fp pin|face pin|userpic pin|biodata|tmp|template|fid|fingerid)/i',$key)) return null;
            }
        }
        return [$code,$name];
    }

    public static function sameName(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && trim($a)!=='' &&
            mb_strtolower(trim(preg_replace('/\s+/u',' ',$a) ?? '')) === mb_strtolower(trim(preg_replace('/\s+/u',' ',$b) ?? ''));
    }

    private static function writableName(string $name): bool
    {
        return strlen($name)<=40 && preg_match('/^[A-Za-z0-9 ._-]+$/D',$name)===1;
    }

    private function register(Device $device,string $code,string $name): object
    {
        return DB::connection('legacy_sqlsrv_writer')->selectOne(
            'EXEC adms.register_device_employee @badge=?, @name=?, @serial=?',
            [$code,$name,$device->serial_number]
        );
    }

    private function repairPlaceholder(string $code,string $name): void
    {
        Employee::query()->where('employee_id',$code)->where('name','User '.$code)
            ->update(['name'=>$name,'sync_name'=>$name]);
    }

    // Record the observation before any directory write. Never change an existing directory name.
    // Matching records can continue through the existing USERINFO parser; new/conflicting records
    // that are not approved cannot overwrite the operational employee record.
    public function inspect(Device $device,array $parsed,string $table,bool $trusted): array
    {
        if (!in_array($table,['','USERINFO','OPERLOG'],true) || !$this->ready() || !$this->registered($device)) {
            return ['parsed'=>$parsed,'observed'=>0];
        }
        $accepted=[]; $observed=0;
        $batchNames=[];
        foreach ($parsed['records'] as $candidate) {
            $identity=self::identity($candidate,$table);
            if ($identity!==null) $batchNames[$identity[0]][mb_strtolower($identity[1])]=true;
        }
        foreach ($parsed['records'] as $record) {
            $identity=self::identity($record,$table);
            if ($identity===null) {
                if ($table!=='OPERLOG') $accepted[]=$record;
                continue;
            }
            [$code,$name]=$identity;
            $directory=DB::connection('legacy_sqlsrv')->table('dbo.Employee')->where('BadgeNumber',$code)->first(['NAME']);
            $state=$directory ? (self::sameName($directory->NAME,$name)?'existing':'duplicate') : 'pending';
            $key=['device_id'=>$device->id,'employee_code'=>$code,'incoming_name'=>$name];
            $observation=DB::connection('sqlsrv')->transaction(function() use($key,$state,$directory,$trusted){
                $old=$this->rows()->where($key)->lockForUpdate()->first();
                if (!$old) {
                    $id=$this->rows()->insertGetId($key+['state'=>$state,'existing_name'=>$directory?->NAME,
                        'verified_source'=>$trusted,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
                    return $this->rows()->where('id',$id)->first();
                }
                // Repeated uploads must not undo a human decision or erase a conflict.
                if (($old->state==='pending' && $state!=='pending') || ($state==='duplicate' && $old->state!=='rejected')) {
                    $this->rows()->where('id',$old->id)->update(['state'=>$state,'existing_name'=>$directory?->NAME,'updated_at'=>now('UTC')]);
                }
                return $this->rows()->where('id',$old->id)->first();
            },3);
            $observed++;
            $state=$observation->state;
            if ($state==='pending' && $trusted && self::writableName($name) && config('zkteco.employee_registration_auto',false)
                && count($batchNames[$code] ?? [])===1
                && !$this->rows()->where('employee_code',$code)->where('id','<>',$observation->id)
                    ->whereIn('state',['pending','duplicate'])->where('incoming_name','<>',$name)->exists()) {
                try {
                    $result=$this->register($device,$code,$name);
                    $state=$result->state;
                    $this->rows()->where('id',$observation->id)->where('state','pending')->update([
                        'state'=>$state,'existing_name'=>$result->existing_name,'verified_source'=>true,'updated_at'=>now('UTC')]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // The durable pending row remains visible for HR; no attendance path is interrupted.
                    $state='pending';
                }
            }
            if (in_array($state,['registered','existing'],true) && (!$directory || self::sameName($directory->NAME,$name))) {
                if ($trusted || $state==='registered') $this->repairPlaceholder($code,$name);
                if ($table!=='OPERLOG' && $trusted && !$this->blocked($code,(int)$device->id)) $accepted[]=$record;
            }
        }
        $parsed['records']=$accepted;
        return ['parsed'=>$parsed,'observed'=>$observed];
    }

    public function blocked(string $code,int $deviceId): bool
    {
        return $this->ready() && $this->rows()->where('employee_code',$code)->where('device_id',$deviceId)
            ->whereIn('state',['pending','duplicate','rejected'])->exists();
    }

    public function approve(int $id,int $actor): void
    {
        if (!$this->ready()) throw ValidationException::withMessages(['registration'=>'Registration storage is not provisioned.']);
        DB::connection('sqlsrv')->transaction(function() use($id,$actor){
            $row=$this->rows()->where('id',$id)->lockForUpdate()->first();
            if (!$row || $row->state!=='pending') throw ValidationException::withMessages(['registration'=>'Only a pending new registration may be approved.']);
            $device=Device::query()->find($row->device_id);
            if (!$device || !$this->registered($device) || !self::writableName($row->incoming_name))
                throw ValidationException::withMessages(['registration'=>'Verify registered device and name (40 ASCII bytes maximum).']);
            $result=$this->register($device,$row->employee_code,$row->incoming_name);
            $this->rows()->where('id',$id)->update(['state'=>$result->state,'existing_name'=>$result->existing_name,
                'reviewed_by'=>$actor,'updated_at'=>now('UTC')]);
            if ($result->state!=='duplicate') $this->repairPlaceholder($row->employee_code,$row->incoming_name);
        },3);
    }
}
