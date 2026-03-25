<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasUuid
{
    /**
     * Auto-assign UUID before model creation.
     *
     * @return void
     */
    protected static function bootHasUuid()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
