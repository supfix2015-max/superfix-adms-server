<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-shot, read-only terminal roster query. Names and PINs stay in file cache,
 * never in employee tables, registration inbox, or the raw request ledger.
 * PUSH firmware does not always tag data batches with the command ID: a
 * temporary per-terminal isolation window also holds concurrent user uploads.
 */
final class DeviceRosterPreviewService
{
    private const GUARD_SECONDS = 7200;
    private const RESULT_SECONDS = 1800;

    private function cache()
    {
        return Cache::store('file');
    }

    private function key(string $serial): string
    {
        return 'adms:roster-preview:'.hash('sha256',$serial);
    }

    public function state(string $serial): ?array
    {
        $state=$this->cache()->get($this->key($serial));
        return is_array($state)?$state:null;
    }

    public function results(string $serial): array
    {
        return array_values((array)$this->cache()->get($this->key($serial).':rows',[]));
    }

    public function start(Device $device,int $actor): void
    {
        $serial=(string)$device->serial_number;
        if (!app(FingerprintReviewService::class)->targets()->contains(fn($d)=>(int)$d->id===(int)$device->id)) {
            throw ValidationException::withMessages(['roster'=>'Only original registered terminals can be queried.']);
        }
        $key=$this->key($serial);
        $this->cache()->lock($key.':lock',10)->block(5,function() use($device,$serial,$key,$actor){
            $old=$this->state($serial);
            if ($old && $old['status']!=='closed') {
                throw ValidationException::withMessages(['roster'=>'A preview already exists. Finish it before sending another query.']);
            }
            $this->cache()->forget($key.':rows');
            $this->cache()->put($key,[
                'device_id'=>(int)$device->id,'status'=>'queued','actor'=>$actor,
                'wire_id'=>random_int(1400000000,1499999999),'created_at'=>time(),
                'issued_at'=>null,'ack_at'=>null,'last_batch_at'=>null,'return_code'=>null,
                'guard_until'=>time()+self::GUARD_SECONDS,'batches'=>0,
            ],self::GUARD_SECONDS);
        });
    }

    private function save(string $key,array $state): void
    {
        $this->cache()->put($key,$state,max(1,$state['guard_until']-time()));
    }

    // Call only after the established scoped fingerprint authorization check.
    public function poll(Device $device): ?array
    {
        $serial=(string)$device->serial_number;
        $state=$this->state($serial);
        if (!$state || $state['status']==='closed' || (int)$state['device_id']!==(int)$device->id) return null;
        $key=$this->key($serial);
        return $this->cache()->lock($key.':lock',10)->block(4,function() use($device,$serial,$key){
            $state=$this->state($serial);
            if (!$state || $state['status']==='closed') return null;
            if ($state['issued_at']!==null) return ['hold'=>true];
            // Do not overlap a USERINFO/FP update already sent to this device.
            if (app(FingerprintReviewService::class)->ready() &&
                DB::connection('sqlsrv')->table(FingerprintReviewService::TABLE)
                    ->where('target_device_id',$device->id)->whereIn('state',['sent_user','sent_fp'])->exists()) return null;
            $state['status']='sent';
            $state['issued_at']=time();
            $this->save($key,$state);
            return ['hold'=>false,'id'=>$state['wire_id'],'payload'=>'C:'.$state['wire_id'].':DATA QUERY USERINFO'];
        });
    }

    private function requestSerial(Request $request): ?string
    {
        if (strcasecmp($request->getHost(),'at1.superfix.ae')!==0) return null;
        $query=$request->query();
        $serial=$query['SN'] ?? $query['sn'] ?? null;
        if (!is_string($serial) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$serial)
            || (isset($query['SN'],$query['sn']) && $query['SN']!==$query['sn'])) return null;
        return $serial;
    }

    public function hasActiveCapture(Request $request,array $context): bool
    {
        if (!$request->isMethod('POST')) return false;
        $table=strtoupper(trim((string)($context['table'] ?? '')));
        if (!in_array($table,['USERINFO','OPERLOG',''],true)) return false;
        if ($table!=='USERINFO' && !preg_match('/(?:^|[\r\n\t ])(?:Name|Card|CardNo)\s*=/i',$context['raw_body'])) return false;
        $serial=$this->requestSerial($request);
        $state=$serial===null?null:$this->state($serial);
        return $state && $state['issued_at'] && $state['status']!=='closed';
    }

    // Invoked before createRawLog(): no raw roster data is written to SQL.
    // ATTLOG and unrelated biometric-only packets continue normally.
    public function capture(Request $request,Device $device,array $context,ZKTecoPayloadParser $parser): ?string
    {
        $serial=$this->requestSerial($request);
        if ($serial===null || !$request->isMethod('POST')) return null;
        $state=$this->state($serial);
        if (!$state || !$state['issued_at'] || $state['status']==='closed' || (int)$state['device_id']!==(int)$device->id) return null;
        $table=strtoupper(trim((string)($context['table'] ?? '')));
        if (!in_array($table,['USERINFO','OPERLOG',''],true) || $context['raw_body']==='') return null;
        $records=$parser->parseUserPayload($context['raw_body'])['records'] ?? [];
        $names=self::previewRows($records,$table);
        // USERINFO is explicitly a user table; suppress even unrecognized batches.
        // For ambiguous OPERLOG, require an actual user-shaped record.
        if ($table!=='USERINFO' && $names===[]) return null;
        $key=$this->key($serial);
        return $this->cache()->lock($key.':lock',10)->block(4,function() use($key,$serial,$names){
            $state=$this->state($serial);
            if (!$state || !$state['issued_at'] || $state['status']==='closed') {
                // Never fall through to import a packet identified as a preview reply.
                return 'OK:'.count($names)."\r\n";
            }
            $rows=(array)$this->cache()->get($key.':rows',[]);
            foreach ($names as $row) {
                $rowKey=hash('sha256',$row['pin']."\0".$row['name']);
                if (count($rows)<10000 || isset($rows[$rowKey])) $rows[$rowKey]=$row;
            }
            $state['last_batch_at']=time();
            $state['batches']++;
            $this->cache()->put($key.':rows',$rows,self::RESULT_SECONDS);
            $this->save($key,$state);
            return 'OK:'.count($names)."\r\n";
        });
    }

    public static function previewRows(array $records,string $table): array
    {
        $rows=[];
        foreach ($records as $record) {
            $raw=array_change_key_case((array)($record['raw'] ?? []),CASE_LOWER);
            if ($table!=='USERINFO' && (!isset($raw['user pin']) && !isset($raw['pin']))) continue;
            if ($table!=='USERINFO' && !array_key_exists('name',$raw) && !array_key_exists('card',$raw) && !array_key_exists('cardno',$raw)) continue;
            if ($table!=='USERINFO' && collect(array_keys($raw))->contains(fn($key)=>preg_match('/^(oplog|fp pin|face pin|userpic pin|biodata|tmp|template|fid|fingerid)/i',$key))) continue;
            $pin=trim((string)($record['employee_code'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$pin) || preg_match('/^OPLOG/i',$pin)) continue;
            $name=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',(string)($record['name'] ?? '')) ?? '');
            if (preg_match('/^USER OPLOG/i',$name)) continue;
            $rows[]=['pin'=>$pin,'name'=>mb_substr($name,0,160)];
        }
        return $rows;
    }

    public function acknowledge(Device $device,array $records): bool
    {
        $serial=(string)$device->serial_number;
        $state=$this->state($serial);
        if (!$state || !$state['issued_at'] || (int)$state['device_id']!==(int)$device->id || count($records)!==1) return false;
        $record=$records[0];
        $id=trim((string)($record['ID'] ?? $record['CmdID'] ?? $record['id'] ?? ''));
        $ret=trim((string)($record['Return'] ?? $record['return'] ?? ''));
        if ($id!==(string)$state['wire_id'] || !preg_match('/^-?\d{1,10}$/D',$ret)) return false;
        $key=$this->key($serial);
        return $this->cache()->lock($key.':lock',10)->block(4,function() use($key,$id,$ret,$serial){
            $state=$this->state($serial);
            if (!$state || (string)$state['wire_id']!==$id) return false;
            if ($state['status']!=='closed'){
                $state['ack_at']=time();
                $state['return_code']=$ret;
                $this->save($key,$state);
            }
            return true;
        });
    }

    public function close(Device $device): void
    {
        $serial=(string)$device->serial_number;
        $key=$this->key($serial);
        $this->cache()->lock($key.':lock',10)->block(5,function() use($key,$serial){
            $state=$this->state($serial);
            if (!$state || $state['status']==='closed') return;
            if ($state['issued_at'] && time()-max($state['issued_at'],$state['last_batch_at'] ?? 0,$state['ack_at'] ?? 0)<90) {
                throw ValidationException::withMessages(['roster'=>'Wait until the device has been quiet for at least 90 seconds before resuming import.']);
            }
            $state['status']='closed';
            $this->save($key,$state);
        });
    }
}
