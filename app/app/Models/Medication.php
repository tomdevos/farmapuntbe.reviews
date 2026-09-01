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

    /**
     * Pseudo-CNKs stand in for products that have no real CNK: compounded
     * preparations ("magistrale bereiding", 9999xxx and Medipim's 99xxxxx)
     * and the synthetic codes the schema importer mints for own products
     * (9800001+, 9810001+). Phil doesn't know them — worse, sending one
     * makes its SPA render an empty interactions page, so the whole check
     * silently returns nothing. No real CNK starts with 98 or 99.
     */
    public static function isPseudoCnk(?string $cnk): bool
    {
        return $cnk !== null && preg_match('/^9[89]\\d{5}$/', $cnk) === 1;
    }

    public function activeIngredients(): BelongsToMany
    {
        return $this->belongsToMany(ActiveIngredient::class, 'medication_ingredient')
            ->withPivot(['qty', 'unit', 'denominator']);
    }
}
