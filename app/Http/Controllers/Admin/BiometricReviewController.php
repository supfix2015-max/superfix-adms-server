<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeBiometric;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class BiometricReviewController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(strcasecmp($request->getHost(), 'at2.superfix.ae') === 0, 404);

        $filters = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $search = trim((string) ($filters['q'] ?? ''));
        $query = EmployeeBiometric::query()->where('biometric_type', EmployeeBiometric::TYPE_FINGERPRINT);
        $count = (clone $query)->count();

        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $query->where(function ($q) use ($pattern) {
                $q->where('employee_code', 'like', $pattern)
                    ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', $pattern))
                    ->orWhereHas('device', fn ($d) => $d->where('name', 'like', $pattern)
                        ->orWhere('serial_number', 'like', $pattern));
            });
        }

        // Do not retrieve or serialize template bytes, raw payload or credentials.
        $rows = $query->select([
            'id', 'employee_id', 'employee_code', 'device_id', 'template_index',
            'is_valid', 'data_size', 'created_at', 'updated_at',
        ])->selectRaw('CASE WHEN DATALENGTH(template_data) > 0 THEN 1 ELSE 0 END AS has_template')
            ->with(['employee:id,employee_id,name,first_name,last_name', 'device:id,name,serial_number'])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->simplePaginate(50)->withQueryString();

        return response()->view('admin.biometrics.review', compact('rows', 'search', 'count'))
            ->header('Cache-Control', 'private, no-store');
    }
}
