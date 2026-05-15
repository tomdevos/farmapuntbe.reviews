<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhilInteraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'resident_id', 'fetched_at', 'cnk_hash',
        'raw_html_path', 'parsed_json',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
        'parsed_json' => 'array',
    ];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(PhilFinding::class);
    }
}
