<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GheopsCriterion extends Model
{
    use HasFactory;

    protected $table = 'gheops_criteria';

    protected $fillable = [
        'list_num', 'nr', 'title', 'comorbiditeit',
        'rationale', 'alternatief', 'source_page',
    ];

    public function activeIngredients(): BelongsToMany
    {
        return $this->belongsToMany(
            ActiveIngredient::class,
            'gheops_criterion_ingredient'
        );
    }

    public function groups()
    {
        return $this->hasMany(GheopsCriterionGroup::class);
    }
}
