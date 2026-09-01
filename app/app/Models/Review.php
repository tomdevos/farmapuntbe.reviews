<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINALIZED = 'finalized';

    protected $fillable = [
        'resident_id', 'user_id', 'started_on', 'due_on',
        'status', 'gheops_screened_at', 'finalized_at', 'doctor_summary_md',
    ];

    protected $casts = [
        'started_on' => 'date',
        'due_on' => 'date',
        'gheops_screened_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ReviewFinding::class)->orderBy('position');
    }

    public function attentions(): HasMany
    {
        return $this->hasMany(ReviewAttention::class)->orderBy('position');
    }
}
