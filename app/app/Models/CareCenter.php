<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class CareCenter extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'address', 'contact'];

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function residents(): HasManyThrough
    {
        return $this->hasManyThrough(Resident::class, Department::class);
    }
}
