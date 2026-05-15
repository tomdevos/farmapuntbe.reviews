<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resident extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_id', 'first_name', 'last_name', 'slug',
        'room', 'doctor_name', 'born_on', 'archived_at',
    ];

    protected $casts = [
        'born_on' => 'date',
        'archived_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->last_name . ' ' . $this->first_name);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(MedicationSchedule::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->latest('started_on');
    }

    public function philInteractions(): HasMany
    {
        return $this->hasMany(PhilInteraction::class)->latest('fetched_at');
    }
}
