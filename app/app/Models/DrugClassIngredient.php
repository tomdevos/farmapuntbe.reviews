<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrugClassIngredient extends Model
{
    protected $table = 'drug_class_ingredients';

    public $timestamps = false;

    protected $fillable = ['drug_class', 'active_ingredient_id'];

    public function activeIngredient(): BelongsTo
    {
        return $this->belongsTo(ActiveIngredient::class);
    }
}
