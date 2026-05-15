<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewAttention extends Model
{
    use HasFactory;

    protected $fillable = ['review_id', 'label', 'body_md', 'position'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
