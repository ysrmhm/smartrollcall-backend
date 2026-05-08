<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Student extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'classroom_id',
        'student_number',
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'must_change_password',
        'last_login_at',
        'avatar',
    ];

    protected $hidden = [
        'password',
    ];

    protected $appends = ['name'];

    protected function casts(): array
    {
        return [
            'password'             => 'hashed',
            'must_change_password' => 'boolean',
            'last_login_at'        => 'datetime',
        ];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class, 'student_interests')
            ->withPivot('level')
            ->withTimestamps();
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim(($this->first_name ?? '').' '.($this->last_name ?? '')));
    }
}
