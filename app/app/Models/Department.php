<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = ['care_center_id', 'name', 'slug'];

    public function careCenter(): BelongsTo
    {
        return $this->belongsTo(CareCenter::class);
    }

    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }
}
