<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MakeupSession extends Model
{
    protected $fillable = [
        'classroom_id',
        'created_by',
        'date',
        'time',
        'day',
        'note',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
