<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Classroom extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'code',
        'department',
        'day',
        'time',
        'status',
        'attendance_taken',
        'file_name',
        'archived_at',
    ];

    protected $casts = [
        'attendance_taken' => 'boolean',
        'archived_at'      => 'datetime',
    ];

    protected $appends = ['students_count'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function attendances(): HasManyThrough
    {
        return $this->hasManyThrough(Attendance::class, Student::class);
    }

    protected function studentsCount(): Attribute
    {
        return Attribute::get(function () {
            // Eager-loaded varsa count'u oradan, yoksa direkt sorgudan
            return $this->relationLoaded('students')
                ? $this->students->count()
                : $this->students()->count();
        });
    }
}
