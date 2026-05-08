<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Interest extends Model
{
    protected $fillable = ['name', 'category', 'icon'];

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_interests')
            ->withPivot('level')
            ->withTimestamps();
    }
}
