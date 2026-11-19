<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DeviceRosterPreviewService;
use App\Services\FingerprintReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;

final class DeviceRosterPreviewController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless(strcasecmp($request->getHost(),'at2.superfix.ae')===0,404);
    }

    public function index(Request $request,DeviceRosterPreviewService $service,FingerprintReviewService $fingerprints): Response
    {
        $this->guard($request);
        $filters=$request->validate(['device_id'=>['nullable','integer','min:1']]);
        $devices=$fingerprints->targets();
        $selected=$devices->first(fn($device)=>(int)$device->id===(int)($filters['device_id'] ?? 0));
        $state=$selected?$service->state($selected->serial_number):null;
        $rows=$selected?$service->results($selected->serial_number):[];
        usort($rows,fn($a,$b)=>strnatcasecmp($a['pin'],$b['pin']) ?: strcasecmp($a['name'],$b['name']));
        return response()->view('admin.devices.roster-preview',compact('devices','selected','state','rows'))
            ->header('Cache-Control','private, no-store');
    }

    public function start(Request $request,DeviceRosterPreviewService $service,FingerprintReviewService $fingerprints): RedirectResponse
    {
        $this->guard($request);
        $data=$request->validate(['device_id'=>['required','integer','min:1'],'confirm'=>['required','accepted']]);
        $device=$fingerprints->targets()->first(fn($d)=>(int)$d->id===(int)$data['device_id']);
        abort_unless($device,404);
        $service->start($device,(int)$request->user()->id);
        return redirect()->route('admin.devices.roster-preview',['device_id'=>$device->id])->with('success','Read-only query queued; wait for the terminal poll.');
    }

    public function close(Request $request,DeviceRosterPreviewService $service,FingerprintReviewService $fingerprints): RedirectResponse
    {
        $this->guard($request);
        $data=$request->validate(['device_id'=>['required','integer','min:1'],'confirm'=>['required','accepted']]);
        $device=$fingerprints->targets()->first(fn($d)=>(int)$d->id===(int)$data['device_id']);
        abort_unless($device,404);
        $service->close($device);
        return redirect()->route('admin.devices.roster-preview',['device_id'=>$device->id])->with('success','Preview ended; normal employee upload handling resumed.');
    }
}
