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

    /** The three schedule types that count as "currently taken". */
    public const ACTIVE_TYPES = [self::TYPE_CHRONIC, self::TYPE_TEMP, self::TYPE_PRN];

    protected $fillable = [
        'resident_id', 'medication_id', 'schedule_type',
        'start_on', 'end_on', 'frequency', 'unit', 'dosages', 'notes',
    ];

    protected $casts = [
        'start_on' => 'date',
        'end_on' => 'date',
        'dosages' => 'array',
    ];

    /** @param  \Illuminate\Database\Eloquent\Builder<self>  $query */
    public function scopeActive($query)
    {
        return $query->whereIn('schedule_type', self::ACTIVE_TYPES);
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }
}
