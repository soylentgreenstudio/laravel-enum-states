<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StateTransition extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'transitioned_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setTable(config('enum-states.table', 'state_transitions'));

        parent::__construct($attributes);
    }

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
