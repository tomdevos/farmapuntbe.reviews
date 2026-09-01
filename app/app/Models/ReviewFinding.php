<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewFinding extends Model
{
    use HasFactory;

    public const SOURCE_GHEOPS = 'gheops';
    public const SOURCE_PHIL = 'phil';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'review_id', 'source', 'severity',
        'active_ingredient_id', 'gheops_criterion_id',
        'title', 'body_md', 'note_md', 'fingerprint', 'dismissed_at', 'position',
    ];

    protected $casts = [
        'dismissed_at' => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function activeIngredient(): BelongsTo
    {
        return $this->belongsTo(ActiveIngredient::class);
    }

    public function gheopsCriterion(): BelongsTo
    {
        return $this->belongsTo(GheopsCriterion::class);
    }
}
