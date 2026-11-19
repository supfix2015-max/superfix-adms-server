<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ZenHrOAuthService;
use App\Services\ZenHrAttendanceOutbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\View\View;
use Throwable;

final class ZenHrIntegrationController extends Controller
{
    private const STATE_SESSION_KEY = 'zenhr.oauth.pending';

    private function guardAt2(Request $request): void
    {
        abort_unless(
            config('zenhr.enabled', false)
            && strcasecmp($request->getHost(), (string) config('zenhr.host')) === 0
            && (string) config('zenhr.redirect_uri') ===
                'https://at2.superfix.ae/admin/integrations/zenhr/callback',
            404
        );
    }

    private function credentialsReady(): bool
    {
        return trim((string) config('zenhr.client_id')) !== ''
            && trim((string) config('zenhr.client_secret')) !== '';
    }

    public function index(Request $request, ZenHrOAuthService $service, ZenHrAttendanceOutbox $outbox): View
    {
        $this->guardAt2($request);

        $outboxStats = $outbox->stats();
        $filters = $request->validate([
            'pending_search' => ['nullable', 'string', 'max:80'],
        ]);
        $pendingSearch = trim((string) ($filters['pending_search'] ?? ''));
        $pendingRows = null;

        if ($outboxStats['ready']) {
            $db = DB::connection('sqlsrv');
            // Only unique directory matches: ambiguous badges/serials must not
            // duplicate an outbox row or attribute a punch to the wrong person.
            $employees = $db->table('dbo.Employee')->select('BadgeNumber')
                ->selectRaw('MIN(Id) AS CanonicalId')
                ->groupBy('BadgeNumber')->havingRaw('COUNT_BIG(*) = 1');
            $devices = $db->table('dbo.Device')->select('DeviceSerialNumber')
                ->selectRaw('MIN(Id) AS CanonicalId')
                ->groupBy('DeviceSerialNumber')->havingRaw('COUNT_BIG(*) = 1');
            $query = $db->table('adms.zenhr_attendance_outbox as o')
                ->leftJoinSub($employees, 'em', 'em.BadgeNumber', '=', 'o.badge_number')
                ->leftJoin('dbo.Employee as e', 'e.Id', '=', 'em.CanonicalId')
                ->leftJoinSub($devices, 'dm', 'dm.DeviceSerialNumber', '=', 'o.device_serial')
                ->leftJoin('dbo.Device as d', 'd.Id', '=', 'dm.CanonicalId')
                ->where('o.delivery_state', 'pending')
                ->select([
                    'o.badge_number', 'e.NAME as employee_name',
                    'o.device_serial', 'd.DeviceAliasName as device_alias',
                    'o.punched_at_local', 'o.direction', 'o.source_kind',
                    'o.attempts', 'o.last_error_code',
                ]);

            if ($pendingSearch !== '') {
                $pattern = '%'.$pendingSearch.'%';
                $query->where(function ($q) use ($pattern) {
                    $q->where('o.badge_number', 'like', $pattern)
                        ->orWhere('e.NAME', 'like', $pattern)
                        ->orWhere('o.device_serial', 'like', $pattern)
                        ->orWhere('d.DeviceAliasName', 'like', $pattern);
                });
            }

            $pendingRows = $query->orderBy('o.punched_at_local')
                ->orderBy('o.event_key')
                ->simplePaginate(50, ['*'], 'pending_page')->withQueryString();
        }

        return view('admin.integrations.zenhr', [
            'credentialsReady' => $this->credentialsReady(),
            'storageReady' => $service->isProvisioned(),
            'connection' => $service->connectionInfo(),
            'syncEnabled' => (bool) config('zenhr.attendance_sync_enabled', false),
            'outboxStats' => $outboxStats,
            'pendingRows' => $pendingRows,
            'pendingSearch' => $pendingSearch,
            'autoSendConfigured' => (bool) config('zenhr.attendance_auto_send', false)
                && (bool) config('zenhr.attendance_post_enabled', false)
                && (bool) config('zenhr.outbox_auto_collect', false),
            'autoCollect' => (bool) config('zenhr.outbox_auto_collect', false),
        ]);
    }

    public function connect(Request $request, ZenHrOAuthService $service): RedirectResponse
    {
        $this->guardAt2($request);

        if (! $this->credentialsReady() || ! $service->isProvisioned()) {
            return back()->withErrors([
                'zenhr' => 'Configure the new AT2 OAuth UID/secret and provision the encrypted adms ZenHR connection table first.',
            ]);
        }

        $state = bin2hex(random_bytes(32));
        $request->session()->put(self::STATE_SESSION_KEY, [
            'hash' => hash('sha256', $state),
            'created' => now('UTC')->timestamp,
            'user_id' => (int) $request->user()->id,
        ]);
        $request->session()->save();

        $params = [
            'app' => (string) config('zenhr.app_name'),
            'response_type' => 'code',
            'client_id' => (string) config('zenhr.client_id'),
            'redirect_uri' => (string) config('zenhr.redirect_uri'),
            'scope' => implode(' ', (array) config('zenhr.scopes', [])),
            'state' => $state,
        ];

        return redirect()->away((string) config('zenhr.authorize_url')
            .'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    public function callback(Request $request, ZenHrOAuthService $service): RedirectResponse
    {
        $this->guardAt2($request);

        $pending = $request->session()->pull(self::STATE_SESSION_KEY);
        $state = $request->query('state');
        if (! is_array($pending)
            || ! is_string($state) || $state === ''
            || ! hash_equals((string) ($pending['hash'] ?? ''), hash('sha256', $state))
            || now('UTC')->timestamp - (int) ($pending['created'] ?? 0) > 600
            || (int) ($pending['user_id'] ?? -1) !== (int) $request->user()->id) {
            return redirect()->route('admin.zenhr.index')
                ->withErrors(['zenhr' => 'OAuth session expired or state validation failed. Start connection again.']);
        }

        if ($request->filled('error')) {
            return redirect()->route('admin.zenhr.index')
                ->withErrors(['zenhr' => 'ZenHR did not authorize the application.']);
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 4096) {
            return redirect()->route('admin.zenhr.index')
                ->withErrors(['zenhr' => 'ZenHR returned no valid authorization code.']);
        }

        try {
            $token = $service->exchangeAuthorizationCode($code);
            $identity = $service->validateAccessToken($token['access_token']);
            $service->storeConnection($token, $identity, (int) $request->user()->id);
        } catch (Throwable $e) {
            // Do not log OAuth URLs, token responses, codes or secrets.
            report(new \RuntimeException('ZenHR OAuth handshake failed at AT2: '.$e->getCode()));
            return redirect()->route('admin.zenhr.index')
                ->withErrors(['zenhr' => 'ZenHR connection could not be verified or stored. Check AT2 configuration and DB permissions.']);
        }

        return redirect()->route('admin.zenhr.index')
            ->with('success', 'ZenHR connected and the access/refresh tokens were stored encrypted. Attendance sync remains OFF.');
    }

    public function test(Request $request, ZenHrOAuthService $service): RedirectResponse
    {
        $this->guardAt2($request);

        try {
            $identity = $service->testStoredConnection();
            $companyId = $identity['token_info']['props']['company_id'] ?? null;
            return back()->with('success', $companyId
                ? 'ZenHR read-only connection verified for company #'.(int) $companyId.'. No attendance sent.'
                : 'ZenHR read-only authentication verified. No attendance sent.');
        } catch (Throwable $e) {
            return back()->withErrors([
                'zenhr' => 'Read-only connection test failed or token expired. Reconnect if necessary.',
            ]);
        }
    }
}
