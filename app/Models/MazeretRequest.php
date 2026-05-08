<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MazeretRequest extends Model
{
    protected $fillable = [
        'student_id',
        'classroom_id',
        'date',
        'reason',
        'file_path',
        'file_original_name',
        'file_mime',
        'file_size',
        'status',
        'reviewer_id',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'date'        => 'date:Y-m-d',
        'reviewed_at' => 'datetime',
        'file_size'   => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}
