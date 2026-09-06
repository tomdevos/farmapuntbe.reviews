<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One reusable sentence per recurring finding — the pharmacist writes her
 * explanation once and it is offered again the next time the same interaction,
 * criterium or product shows up (for another resident or another wzc).
 */
class FindingNoteTemplate extends Model
{
    protected $fillable = ['key', 'source', 'label', 'note_md', 'times_used', 'last_used_at'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'times_used' => 'integer',
    ];
}
