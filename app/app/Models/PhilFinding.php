<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhilFinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'phil_interaction_id', 'severity', 'med_a', 'med_b', 'advies',
    ];

    public function interaction(): BelongsTo
    {
        return $this->belongsTo(PhilInteraction::class, 'phil_interaction_id');
    }
}
