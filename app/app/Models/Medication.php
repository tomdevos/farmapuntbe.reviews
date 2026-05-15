<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Medication extends Model
{
    use HasFactory;

    protected $fillable = [
        'cnk', 'name', 'brand', 'manufacturer', 'form',
        'atc_code', 'category', 'is_robot', 'status',
        'bron', 'aliassen', 'medicatiegroep',
    ];

    protected $casts = [
        'is_robot' => 'boolean',
        'aliassen' => 'array',
        'medicatiegroep' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'cnk';
    }

    public function setCnkAttribute(?string $value): void
    {
        $this->attributes['cnk'] = self::normalizeCnk($value);
    }

    public static function normalizeCnk(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value);
        if ($digits === '') {
            return null;
        }
        return str_pad($digits, 7, '0', STR_PAD_LEFT);
    }

    public function activeIngredients(): BelongsToMany
    {
        return $this->belongsToMany(ActiveIngredient::class, 'medication_ingredient')
            ->withPivot(['qty', 'unit', 'denominator']);
    }
}
