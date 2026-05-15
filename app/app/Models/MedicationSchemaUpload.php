<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationSchemaUpload extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'care_center_id', 'filename', 'stored_path',
        'uploaded_at', 'processed_at', 'status', 'summary', 'error',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'processed_at' => 'datetime',
        'summary' => 'array',
    ];

    public function careCenter(): BelongsTo
    {
        return $this->belongsTo(CareCenter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
