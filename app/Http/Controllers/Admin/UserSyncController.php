<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UserSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserSyncController extends Controller
{
    public function __construct(
        protected UserSyncService $userSyncService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $result = $this->userSyncService->queueGlobalUserSync(
                $request->boolean('force'),
                'employee_page_ajax'
            );
        } catch (\Throwable $exception) {
            Log::error('adms.user_sync.queue_failed', [
                'user_id' => $request->user()?->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to queue user sync batches right now.',
                'error' => 'sync_queue_failed',
            ], 500);
        }

        if ($result['summary']['active_device_count'] === 0) {
            Log::warning('adms.user_sync.no_active_devices', [
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'No active devices are available for user sync.',
                'error' => 'no_active_devices',
                'summary' => $result['summary'],
            ], 422);
        }

        if ($result['summary']['safe_user_count'] === 0) {
            Log::warning('adms.user_sync.no_safe_users', [
                'user_id' => $request->user()?->id,
                'summary' => $result['summary'],
            ]);

            return response()->json([
                'message' => 'No sync-safe users are available for device sync.',
                'error' => 'no_sync_safe_users',
                'summary' => $result['summary'],
            ], 422);
        }

        Log::info('adms.user_sync.queued', [
            'user_id' => $request->user()?->id,
            'sync_ids' => $result['sync_ids'],
            'summary' => $result['summary'],
        ]);

        $message = $result['summary']['new_batch_count'] > 0
            ? sprintf(
                'Queued %d user and biometric sync batch(es) for %d active device(s).',
                $result['summary']['new_batch_count'],
                $result['summary']['active_device_count'],
            )
            : sprintf(
                'User and biometric sync was already queued for %d active device(s).',
                $result['summary']['active_device_count'],
            );

        return response()->json([
            'message' => $message,
            'summary' => $result['summary'],
            'sync_ids' => $result['sync_ids'],
            'batches' => $result['batches'],
            'status_url' => route('admin.user-syncs.status'),
        ], 202);
    }

    public function status(Request $request): JsonResponse
    {
        $syncIds = collect($request->input('sync_ids', []));

        if (! is_array($request->input('sync_ids'))) {
            $syncIds = collect(preg_split('/[\s,]+/', (string) $request->input('sync_ids', ''), -1, PREG_SPLIT_NO_EMPTY));
        }

        $syncIds = $syncIds
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($syncIds === []) {
            return response()->json([
                'message' => 'At least one sync batch ID is required.',
                'error' => 'missing_sync_ids',
            ], 422);
        }

        return response()->json([
            'message' => 'Sync batch status loaded.',
            ...$this->userSyncService->syncBatchStatus($syncIds),
        ]);
    }
}
