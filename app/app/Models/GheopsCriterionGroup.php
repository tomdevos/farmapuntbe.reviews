<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GheopsCriterionGroup extends Model
{
    protected $table = 'gheops_criterion_group';

    public $timestamps = false;

    protected $fillable = ['gheops_criterion_id', 'drug_class'];

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(GheopsCriterion::class, 'gheops_criterion_id');
    }
}
