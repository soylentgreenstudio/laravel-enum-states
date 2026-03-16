<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StateTransition extends Model
{
    public $timestamps = false;

    protected $table = 'state_transitions';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'transitioned_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->created_at ??= now();
        });
    }
}
