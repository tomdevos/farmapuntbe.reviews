<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewExport extends Model
{
    use HasFactory;

    public const SCOPE_RESIDENT = 'resident';
    public const SCOPE_DEPARTMENT = 'department';
    public const SCOPE_CARE_CENTER = 'care_center';

    public const FORMAT_PDF = 'pdf';
    public const FORMAT_DOCX = 'docx';

    protected $fillable = [
        'scope', 'scope_id', 'format', 'path',
        'generated_by', 'generated_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
