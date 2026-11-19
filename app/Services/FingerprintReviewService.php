<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeBiometric;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FingerprintReviewService
{
    public const TABLE = 'adms.fingerprint_sync_reviews';
    // Separate from ordinary queue IDs, legacy time Unix IDs and ATTLOG replay IDs.
    private const WIRE_BASE = 1200000000;
    private const WIRE_END = 1300000000;
    public const HASH_SQL = "CONVERT(varchar(64), HASHBYTES('SHA2_256', CONVERT(varchar(max), template_data)), 2)";

    private function db()
    {
        return DB::connection('sqlsrv');
    }

    public function ready(): bool
    {
        return $this->db()->selectOne("SELECT OBJECT_ID(N'adms.fingerprint_sync_reviews', N'U') AS object_id")?->object_id !== null;
    }

    private function rows()
    {
        return $this->db()->table(self::TABLE);
    }

    public function targets()
    {
        $serials = DB::connection('legacy_sqlsrv')->table('dbo.Device')
            ->whereNotNull('DeviceSerialNumber')->distinct()->pluck('DeviceSerialNumber')->all();
        return Device::query()->whereIn('serial_number', $serials)
            ->whereIn('status', [Device::STATUS_ACTIVE, Device::STATUS_OFFLINE])
            ->orderBy('name')->get(['id','name','serial_number','status','last_seen','last_heartbeat']);
    }

    private function registered(Device $device): bool
    {
        return ! $device->trashed()
            && in_array($device->status, [Device::STATUS_ACTIVE, Device::STATUS_OFFLINE], true)
            && $device->serial_number
            && DB::connection('legacy_sqlsrv')->table('dbo.Device')
                ->where('DeviceSerialNumber', $device->serial_number)->exists();
    }

    private function version(EmployeeBiometric $row): string
    {
        return strtoupper(hash('sha256', (string) $row->template_data));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['fingerprint_review' => $message]);
    }

    private function employeeName(string $code): string
    {
        $matches = DB::connection('legacy_sqlsrv')->table('dbo.Employee')->where('BadgeNumber', $code)
            ->limit(2)->get(['BadgeNumber','NAME']);
        if ($matches->count() !== 1 || trim((string) $matches->first()->BadgeNumber) !== $code) {
            $this->invalid('Employee badge is missing or ambiguous in the original HR directory.');
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $matches->first()->NAME) ?? '');
        if ($name === '' || mb_strlen($name) > 160) {
            $this->invalid('Employee name is missing or invalid in the original HR directory.');
        }
        return $name;
    }

    private function validTemplate(EmployeeBiometric $row): bool
    {
        $data = (string) $row->template_data;
        return $row->biometric_type === EmployeeBiometric::TYPE_FINGERPRINT
            && $row->is_valid !== false
            && preg_match('/^\d{1,32}$/D', (string) $row->employee_code) === 1
            && preg_match('/^[0-9]$/D', (string) $row->template_index) === 1
            && $data !== '' && strlen($data) <= 65536
            && preg_match('/^[A-Za-z0-9+\/=]+$/D', $data) === 1
            && base64_decode($data, true) !== false;
    }

    private function sourceFingerprintMatches(EmployeeBiometric $row): bool
    {
        if (app(DeviceEmployeeRegistrationService::class)->blocked((string)$row->employee_code,(int)$row->device_id)) return false;
        if (! $row->employee_id) return true; // HR directory must still match exactly.
        $employee = Employee::query()->find($row->employee_id);
        if (! $employee || ! Employee::isSyncEligibleStatus($employee->status)
            || (string) $employee->employee_id !== (string) $row->employee_code) return false;
        foreach ([$employee->sync_uid, $employee->uid] as $pin) {
            if ($pin !== null && $pin !== '' && (string) $pin !== (string) $row->employee_code) return false;
        }
        return true;
    }

    private function existingState(EmployeeBiometric $row, int $targetId, string $hash): ?string
    {
        $existing = EmployeeBiometric::query()->where('device_id', $targetId)
            ->where('employee_code', $row->employee_code)
            ->where('biometric_type', EmployeeBiometric::TYPE_FINGERPRINT)
            ->where('template_index', $row->template_index)->get(['id','template_data','is_valid']);
        if ($existing->isEmpty()) return null;
        foreach ($existing as $item) {
            if ($item->is_valid === false || ! hash_equals($hash, $this->version($item))) return 'conflict';
        }
        return 'already_present';
    }

    private function algorithmMismatch(Device $source, Device $target): bool
    {
        $compat = app(BiometricSyncService::class)->validateDeviceCompatibility($source, $target);
        foreach ($compat['warnings'] ?? [] as $warning) {
            if (str_starts_with($warning, 'Fingerprint algorithm mismatch')) return true;
        }
        return false;
    }

    public function decide(int $sourceId, string $hash, string $action, array $targetIds, bool $createUser, int $actor): int
    {
        if (! $this->ready()) $this->invalid('Fingerprint review storage must be provisioned first.');
        return $this->db()->transaction(function () use ($sourceId, $hash, $action, $targetIds, $createUser, $actor) {
            $row = EmployeeBiometric::query()->whereKey($sourceId)->lockForUpdate()->firstOrFail();
            if ($row->biometric_type !== EmployeeBiometric::TYPE_FINGERPRINT
                || ! hash_equals($this->version($row), $hash)) $this->invalid('Template changed. Refresh and review the new version.');
            $base = [
                'source_biometric_id' => $row->id, 'version_hash' => $hash,
                'source_device_id' => $row->device_id, 'employee_code' => $row->employee_code,
                'finger_slot' => (int) $row->template_index, 'approved_by' => $actor,
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ];
            $prior = $this->rows()->where('source_biometric_id', $row->id)->where('version_hash', $hash)->get();
            if ($action === 'reject') {
                if ($prior->isNotEmpty()) $this->invalid('This version already has a recorded decision.');
                if (! preg_match('/^[0-9]$/D', (string) $row->template_index)) $this->invalid('Cannot record an invalid finger slot.');
                $this->rows()->insert($base + ['target_device_id'=>0,'target_serial'=>'','user_name'=>'','state'=>'rejected']);
                return 1;
            }
            if ($prior->contains(fn ($r) => $r->state === 'rejected')) $this->invalid('This version was rejected.');
            if (! $this->validTemplate($row) || ! $this->sourceFingerprintMatches($row)) {
                $this->invalid('Fingerprint or employee identity is invalid. Verify enrollment and the HR directory.');
            }
            $name = $this->employeeName((string) $row->employee_code);
            $source = Device::query()->find($row->device_id);
            if (! $source || ! $this->registered($source)) $this->invalid('The source device is not in the authorized original roster.');
            $targets = $this->targets()->keyBy('id');
            $added = 0;
            foreach (array_unique($targetIds) as $id) {
                $id = (int) $id;
                $target = $targets->has($id) ? Device::query()->whereKey($id)->lockForUpdate()->first() : null;
                if (! $target || $id === (int) $source->id) $this->invalid('Select only registered target devices other than the source.');
                if ($prior->contains(fn ($r) => (int) $r->target_device_id === $id)) continue;
                if (app(DeviceEmployeeRegistrationService::class)->blocked((string)$row->employee_code,$id)) {
                    $this->invalid('Target device has an unresolved employee registration conflict.');
                }
                $state = $this->existingState($row, $id, $hash);
                if ($state === null) {
                    // A different source may have queued the same employee/slot.
                    // Do not permit two versions to overwrite one another.
                    $other = $this->rows()->where('target_device_id',$id)
                        ->where('employee_code',$row->employee_code)->where('finger_slot',(int)$row->template_index)
                        ->whereIn('state',['pending_user','sent_user','pending_fp','sent_fp','done','already_present','unknown'])
                        ->orderBy('id')->get(['version_hash','state']);
                    if ($other->contains(fn ($r)=>! hash_equals(trim($r->version_hash),$hash))) $state = 'conflict';
                    elseif ($other->contains(fn ($r)=>in_array($r->state,['done','already_present'],true))) $state = 'already_present';
                    elseif ($other->isNotEmpty()) $state = 'conflict';
                }
                if ($state === null && $this->algorithmMismatch($source, $target)) $state = 'conflict';
                $this->rows()->insert($base + [
                    'target_device_id'=>$id,'target_serial'=>$target->serial_number,'user_name'=>$name,
                    'state'=>$state ?? ($createUser ? 'pending_user' : 'pending_fp'),
                    'last_result'=>$state === 'conflict' ? 'known_slot_or_algorithm_conflict' : null,
                ]);
                $added++;
            }
            return $added;
        }, 3);
    }

    public function authorized(Request $request, Device $device, bool $tokenVerified): bool
    {
        if (! config('zkteco.fingerprint_review_dispatch', false)) return false;
        if ($tokenVerified) return true;
        // Explicit static serial/IP mapping, never inferred from heartbeat or
        // forwarded headers supplied by an untrusted client. No serial-only release.
        $mapping = (array) config('zkteco.fingerprint_review_trusted_sources', []);
        $expected = $mapping[(string) $device->serial_number] ?? null;
        if (! is_string($expected) || filter_var($expected,FILTER_VALIDATE_IP) === false) return false;
        $proxies = (array) config('zkteco.fingerprint_review_trusted_proxies', []);
        // Never treat the reverse proxy or a wildcard as a device identity.
        if (in_array($expected,$proxies,true) || in_array($expected,['127.0.0.1','::1','0.0.0.0','::'],true)) return false;
        $ip = self::verifiedSourceIp((string)$request->server('REMOTE_ADDR'), (string)$request->header('X-Forwarded-For',''), $proxies);
        return $ip !== null && hash_equals($expected,$ip);
    }

    public static function verifiedSourceIp(string $remote, string $forwarded, array $proxies): ?string
    {
        if (filter_var($remote,FILTER_VALIDATE_IP) === false) return null;
        if (! in_array($remote,$proxies,true)) {
            // Ignore attacker-provided forwarded headers on direct requests.
            return $remote;
        }
        // Explicitly trusted proxies must append/sanitize client IP. Walk
        // right-to-left; never believe an arbitrary leftmost forwarded value.
        $chain = array_map('trim',explode(',',$forwarded));
        if (count($chain) > 16) return null;
        foreach (array_reverse($chain) as $hop) {
            if (filter_var($hop,FILTER_VALIDATE_IP) === false) return null;
            if (! in_array($hop,$proxies,true)) return $hop;
        }
        return null;
    }

    public static function isLegacyFingerprintPush(\App\Models\DeviceCommand $command): bool
    {
        $type = strtolower(trim((string) ($command->parameters['biometric_type'] ?? '')));
        if ($command->command === 'SYNC_BIOMETRICS' && ! in_array($type,['face','photo'],true)) return true;
        return preg_match('/\bDATA\s+UPDATE\s+FINGERTMP\b/i', (string)$command->command.' '.(string)$command->command_payload) === 1;
    }

    public static function wireId(array $record): ?int
    {
        $raw = trim((string) ($record['ID'] ?? $record['CmdID'] ?? $record['id'] ?? ''));
        if (! ctype_digit($raw)) return null;
        $id = (int) $raw;
        return $id > self::WIRE_BASE && $id < self::WIRE_END ? $id : null;
    }

    private function setState(int $id, string $state, ?string $result = null, bool $sent = false): void
    {
        $this->rows()->where('id',$id)->update([
            'state'=>$state,'last_result'=>$result,'updated_at'=>now('UTC'),
            'sent_at'=>$sent ? now('UTC') : null,
        ]);
    }

    public function next(Device $device): ?array
    {
        if (! $this->ready() || ! $this->registered($device)) return null;
        return $this->db()->transaction(function () use ($device) {
            // Serialize polls for one target; do not duplicate an in-flight command.
            $locked = Device::query()->whereKey($device->id)->lockForUpdate()->first();
            if (! $locked || $locked->serial_number !== $device->serial_number) return null;
            $queue = $this->rows()->where('target_device_id',$device->id)->where('target_serial',$device->serial_number);
            (clone $queue)->whereIn('state',['sent_user','sent_fp'])->where('sent_at','<',now('UTC')->subMinutes(5))
                ->update(['state'=>'unknown','last_result'=>'ack_timeout_no_retry','updated_at'=>now('UTC')]);
            if ((clone $queue)->whereIn('state',['sent_user','sent_fp'])->exists()) return null;
            $job = (clone $queue)->whereIn('state',['pending_user','pending_fp'])->orderBy('id')->lockForUpdate()->first();
            if (! $job) return null;
            $row = EmployeeBiometric::query()->whereKey($job->source_biometric_id)->lockForUpdate()->first();
            if (! $row || ! $this->validTemplate($row) || ! $this->sourceFingerprintMatches($row)
                || ! hash_equals(trim($job->version_hash), $this->version($row))
                || (int) $row->device_id !== (int) $job->source_device_id
                || (string) $row->employee_code !== (string) $job->employee_code
                || (int) $row->template_index !== (int) $job->finger_slot) {
                $this->setState($job->id,'stale','source_version_or_identity_changed');
                return null;
            }
            $source = Device::query()->find($row->device_id);
            if (! $source || ! $this->registered($source)) {
                $this->setState($job->id,'stale','source_not_registered');
                return null;
            }
            if (app(DeviceEmployeeRegistrationService::class)->blocked((string)$row->employee_code,(int)$device->id)) {
                $this->setState($job->id,'conflict','employee_registration_conflict');
                return null;
            }
            if ($this->algorithmMismatch($source,$device)) {
                $this->setState($job->id,'conflict','algorithm_mismatch');
                return null;
            }
            $state = $this->existingState($row,(int)$device->id,trim($job->version_hash));
            if ($state === null) {
                $prior = $this->rows()->where('target_device_id',$device->id)->where('id','<',$job->id)
                    ->where('employee_code',$job->employee_code)->where('finger_slot',$job->finger_slot)
                    ->whereIn('state',['sent_fp','done','already_present','unknown'])->get(['version_hash','state']);
                if ($prior->contains(fn ($r)=>! hash_equals(trim($r->version_hash),trim($job->version_hash)))) $state = 'conflict';
                elseif ($prior->contains(fn ($r)=>in_array($r->state,['done','already_present'],true))) $state = 'already_present';
                elseif ($prior->isNotEmpty()) $state = 'conflict';
            }
            if ($state !== null) {
                $this->setState($job->id,$state);
                return null;
            }
            // Recheck the immutable badge/name against the original directory.
            try { $currentName = $this->employeeName($job->employee_code); }
            catch (ValidationException) {
                $this->setState($job->id,'stale','employee_directory_changed');
                return null;
            }
            if ($currentName !== $job->user_name) {
                $this->setState($job->id,'stale','employee_name_changed');
                return null;
            }
            $isUser = $job->state === 'pending_user';
            $wire = self::WIRE_BASE + 2*(int)$job->id + ($isUser ? 0 : 1);
            if ($isUser) {
                // Explicitly opted-in user precondition, no password/card data.
                $payload = "C:$wire:DATA UPDATE USERINFO PIN={$job->employee_code}\tName={$job->user_name}\tPri=0";
            } else {
                $size = (int) ($row->data_size ?: strlen((string)$row->template_data));
                if ($size < 1 || $size > 65536) {
                    $this->setState($job->id,'failed','invalid_template_size');
                    return null;
                }
                $payload = "C:$wire:DATA UPDATE FINGERTMP PIN={$job->employee_code}\tFID={$job->finger_slot}\tSize=$size\tValid=1\tTMP={$row->template_data}";
            }
            $this->setState($job->id,$isUser?'sent_user':'sent_fp',null,true);
            return ['id'=>$wire,'kind'=>$isUser?'USERINFO':'FINGERTMP','payload'=>$payload];
        }, 3);
    }

    public function acknowledge(Device $device, array $record): bool
    {
        $wire = self::wireId($record);
        if ($wire === null || ! $this->ready() || ! $this->registered($device)) return false;
        $offset = $wire - self::WIRE_BASE;
        $id = intdiv($offset,2);
        $isUser = $offset % 2 === 0;
        return $this->db()->transaction(function () use ($device,$id,$isUser,$record) {
            $job = $this->rows()->where('id',$id)->where('target_device_id',$device->id)
                ->where('target_serial',$device->serial_number)->lockForUpdate()->first();
            if (! $job) return false;
            if ($job->state !== ($isUser?'sent_user':'sent_fp')) return true; // duplicate or late ACK; no mutation
            $returnCode = trim((string) ($record['Return'] ?? ''));
            if ($returnCode === '0') $this->setState($id,$isUser?'pending_fp':'done','device_ack_0');
            else $this->setState($id,'failed','device_rejected_or_unrecognized_ack');
            return true;
        });
    }
}
