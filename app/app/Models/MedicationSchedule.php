<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationSchedule extends Model
{
    use HasFactory;

    public const TYPE_CHRONIC = 'chronic';
    public const TYPE_TEMP = 'temp';
    public const TYPE_PRN = 'prn';
    public const TYPE_FORBIDDEN = 'forbidden';

    protected $fillable = [
        'resident_id', 'medication_id', 'schedule_type',
        'start_on', 'end_on', 'frequency', 'unit', 'dosages', 'notes',
    ];

    protected $casts = [
        'start_on' => 'date',
        'end_on' => 'date',
        'dosages' => 'array',
    ];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }
}
