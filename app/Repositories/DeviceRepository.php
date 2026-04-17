<?php

namespace App\Repositories;

use App\Models\Device;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class DeviceRepository extends BaseRepository
{
    public function __construct(Device $model)
    {
        parent::__construct($model);
    }

    public function recentDevices(int $limit = 5): Collection
    {
        return $this->model
            ->latest('last_seen')
            ->latest('last_heartbeat')
            ->limit($limit)
            ->get();
    }

    public function findBySN(string $sn): ?Device
    {
        return $this->model
            ->where('serial_number', $sn)
            ->orWhere('sn', $sn)
            ->first();
    }

    public function findByToken(string $token): ?Device
    {
        return $this->model->where('api_token', $token)->first();
    }

    public function getActive(): Collection
    {
        return $this->model->active()->get();
    }

    public function getOnline(): Collection
    {
        return $this->model->online()->get();
    }

    public function paginateWithFilters(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->query();

        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['device_type'])) {
            $query->where('device_type', $filters['device_type']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('name', 'like', $term)
                    ->orWhere('serial_number', 'like', $term)
                    ->orWhere('sn', 'like', $term)
                    ->orWhere('ip_address', 'like', $term);
            });
        }

        return $query->latest('created_at')->paginate($perPage);
    }

    public function updateLastHeartbeat(int $deviceId): bool
    {
        return $this->update($deviceId, [
            'last_seen' => now(),
            'last_heartbeat' => now(),
            'status' => Device::STATUS_ACTIVE,
        ]);
    }

    public function updateLastSync(int $deviceId): bool
    {
        return $this->update($deviceId, ['last_sync' => now()]);
    }

    public function getStatistics(): array
    {
        $threshold = now()->subMinutes((int) config('zkteco.heartbeat_ttl_minutes', 5));

        $online = $this->model->query()
            ->where(function ($builder) use ($threshold): void {
                $builder->where('last_seen', '>', $threshold)
                    ->orWhere('last_heartbeat', '>', $threshold);
            })
            ->count();

        return [
            'total' => $this->count(),
            'active' => $this->model->where('status', Device::STATUS_ACTIVE)->count(),
            'online' => $online,
            'inactive' => $this->model->where('status', Device::STATUS_INACTIVE)->count(),
            'offline' => max($this->count() - $online, 0),
        ];
    }
}
