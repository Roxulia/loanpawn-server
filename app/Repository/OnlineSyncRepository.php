<?php

namespace App\Repository;

use Illuminate\Database\Eloquent\Model;

class OnlineSyncRepository
{
    public function find(string $modelClass, int $recordId): ?Model
    {
        return $modelClass::query()->find($recordId);
    }

    public function create(string $modelClass, array $data, ?int $recordId = null): Model
    {
        /** @var Model $model */
        $model = new $modelClass();

        if ($recordId !== null) {
            $model->setAttribute($model->getKeyName(), $recordId);
        }

        $model->forceFill($data);
        $model->save();

        return $model->refresh();
    }

    public function update(Model $model, array $data): Model
    {
        $model->forceFill($data);
        $model->save();

        return $model->refresh();
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }
}
