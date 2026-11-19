<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DeviceEmployeeRegistrationService;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

final class DeviceEmployeeRegistrationController extends Controller
{
    public function index(Request $request, DeviceEmployeeRegistrationService $service): Response
    {
        abort_unless(strcasecmp($request->getHost(),'at2.superfix.ae')===0,404);
        $filter=$request->validate(['state'=>['nullable',Rule::in(['all','pending','duplicate','registered','existing','rejected'])]]);
        $state=$filter['state'] ?? 'all';
        $ready=$service->ready();
        $rows=null; $devices=collect(); $counts=collect();
        if ($ready) {
            $query=$service->rows();
            if ($state!=='all') $query->where('state',$state);
            $rows=$query->orderByRaw("CASE state WHEN 'duplicate' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
                ->orderByDesc('id')->simplePaginate(50)->withQueryString();
            $devices=Device::query()->whereIn('id',$rows->getCollection()->pluck('device_id'))->get(['id','name','serial_number'])->keyBy('id');
            $counts=$service->rows()->select('state')->selectRaw('COUNT_BIG(*) AS total')->groupBy('state')->pluck('total','state');
        }
        return response()->view('admin.employees.device-registration',compact('state','ready','rows','devices','counts'))
            ->header('Cache-Control','private, no-store');
    }

    public function approve(Request $request,int $registration,DeviceEmployeeRegistrationService $service): RedirectResponse
    {
        abort_unless(strcasecmp($request->getHost(),'at2.superfix.ae')===0,404);
        $request->validate(['confirm'=>['required','accepted']]);
        $service->approve($registration,(int)$request->user()->id);
        return redirect()->route('admin.employees.device-registration')->with('success','Registration reviewed. Check the result; existing employees were not overwritten.');
    }
}
