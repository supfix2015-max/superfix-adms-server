<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeBiometric;
use App\Services\FingerprintReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class BiometricReviewController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless(strcasecmp($request->getHost(), 'at2.superfix.ae') === 0, 404);
    }

    public function index(Request $request, FingerprintReviewService $service): Response
    {
        $this->guard($request);
        $filters = $request->validate(['q'=>['nullable','string','max:80']]);
        $search = trim((string) ($filters['q'] ?? ''));
        $query = EmployeeBiometric::query()->where('biometric_type', EmployeeBiometric::TYPE_FINGERPRINT);
        $count = (clone $query)->count();

        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $query->where(function ($q) use ($pattern) {
                $q->where('employee_code','like',$pattern)
                    ->orWhereHas('employee',fn ($e)=>$e->where('name','like',$pattern))
                    ->orWhereHas('device',fn ($d)=>$d->where(fn ($d)=>$d->where('name','like',$pattern)
                        ->orWhere('serial_number','like',$pattern)));
            });
        }

        // Only metadata is sent to the admin browser. The digest binds a
        // decision to the exact template without exposing biometric bytes.
        $rows = $query->select([
            'id','employee_id','employee_code','device_id','template_index',
            'is_valid','data_size','created_at','updated_at',
        ])->selectRaw('CASE WHEN DATALENGTH(template_data) > 0 THEN 1 ELSE 0 END AS has_template')
            ->selectRaw(FingerprintReviewService::HASH_SQL.' AS version_hash')
            ->with(['employee:id,employee_id,name','device:id,name,serial_number,sn'])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->simplePaginate(50)->withQueryString();

        $directoryNames = collect();
        if ($rows->isNotEmpty()) {
            try {
                $directoryNames = DB::connection('legacy_sqlsrv')->table('dbo.Employee')
                    ->select('BadgeNumber')->selectRaw('MAX(NAME) AS employee_name')
                    ->whereIn('BadgeNumber',$rows->getCollection()->pluck('employee_code')->unique()->all())
                    ->groupBy('BadgeNumber')->havingRaw('COUNT_BIG(*) = 1')->pluck('employee_name','BadgeNumber');
            } catch (\Illuminate\Database\QueryException $e) {
                // Existing ADMS metadata remains available if the directory is down.
            }
        }
        $ready = $service->ready();
        $targets = $ready ? $service->targets() : collect();
        $decisions = collect();
        $counts = collect();
        if ($ready) {
            $db = DB::connection('sqlsrv');
            $decisions = $db->table(FingerprintReviewService::TABLE)
                ->whereIn('source_biometric_id',$rows->getCollection()->pluck('id')->all())
                ->orderBy('id')->get()->groupBy('source_biometric_id');
            $counts = $db->table(FingerprintReviewService::TABLE)->select('state')
                ->selectRaw('COUNT_BIG(*) AS total')->groupBy('state')->pluck('total','state');
        }
        $enabled = (bool) config('zkteco.fingerprint_review_dispatch',false);
        $registration = app(\App\Services\DeviceEmployeeRegistrationService::class);
        $duplicateCount = $registration->ready() ? $registration->rows()->where('state','duplicate')->count() : 0;
        return response()->view('admin.biometrics.review',compact(
            'rows','search','count','ready','targets','decisions','counts','enabled','directoryNames','duplicateCount'
        ))->header('Cache-Control','private, no-store');
    }

    public function decide(Request $request, int $biometric, FingerprintReviewService $service): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'version'=>['required','regex:/^[A-F0-9]{64}$/D'],
            'action'=>['required',Rule::in(['approve','reject'])],
            'devices'=>['required_if:action,approve','array','max:100'],
            'devices.*'=>['integer','min:1','distinct'],
            'confirm'=>['exclude_unless:action,approve','required','accepted'],
            'create_user'=>['nullable','boolean'],
        ]);
        $added = $service->decide($biometric,$data['version'],$data['action'],
            $data['devices'] ?? [],$request->boolean('create_user'),(int)$request->user()->id);
        return redirect()->route('admin.biometrics.review')->with('success',
            $data['action']==='reject' ? 'Fingerprint version rejected.' :
                $added.' target decision(s) recorded. Delivery requires an authorized device poll.');
    }
}
