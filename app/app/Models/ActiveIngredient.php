<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ActiveIngredient extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name', 'atc_code', 'drug_class'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function medications(): BelongsToMany
    {
        return $this->belongsToMany(Medication::class, 'medication_ingredient')
            ->withPivot(['qty', 'unit', 'denominator']);
    }

    public function gheopsCriteria(): BelongsToMany
    {
        return $this->belongsToMany(GheopsCriterion::class, 'gheops_criterion_ingredient');
    }
}
