<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository
{
    protected Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function all()
    {
        return $this->model->all();
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->paginate($perPage);
    }

    public function find(int $id): ?Model
    {
        return $this->model->find($id);
    }

    public function findOrFail(int $id): Model
    {
        return $this->model->findOrFail($id);
    }

    public function findBy(string $column, $value): ?Model
    {
        return $this->model->where($column, $value)->first();
    }

    public function create(array $attributes): Model
    {
        return $this->model->create($attributes);
    }

    public function update(int $id, array $attributes): bool
    {
        $model = $this->find($id);

        return $model ? $model->update($attributes) : false;
    }

    public function delete(int $id): bool
    {
        $model = $this->find($id);

        return $model ? $model->delete() : false;
    }

    public function restore(int $id): bool
    {
        $model = $this->model->withTrashed()->find($id);

        return $model ? $model->restore() : false;
    }

    public function count(): int
    {
        return $this->model->count();
    }

    public function exists(string $column, $value): bool
    {
        return $this->model->where($column, $value)->exists();
    }

    protected function applyFilters($query, array $filters)
    {
        foreach ($filters as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $query->where($column, $value);
        }

        return $query;
    }
}
