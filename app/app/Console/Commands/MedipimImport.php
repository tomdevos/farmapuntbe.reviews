<?php

namespace App\Console\Commands;

use App\Models\ActiveIngredient;
use App\Models\Medication;
use App\Support\Slug;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MedipimImport extends Command
{
    protected $signature = 'medipim:import
                            {--csv= : Path to products.csv (defaults to {vault}/sources/products.csv)}
                            {--only-known : Only update medications that already exist in DB}
                            {--dry-run}';

    protected $description = 'Import Medipim products.csv into medications + active_ingredients.';

    public function handle(): int
    {
        $vault = rtrim((string) env('VAULT_PATH', dirname(base_path())), '/');
        $csv = $this->option('csv') ?: $vault . '/sources/products.csv';
        if (! is_file($csv)) {
            $this->error("CSV not found at {$csv}.");
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $onlyKnown = (bool) $this->option('only-known');

        $fh = fopen($csv, 'r');
        if ($fh === false) {
            $this->error("Could not open {$csv}.");
            return self::FAILURE;
        }

        $header = fgetcsv($fh);
        if (! is_array($header)) {
            fclose($fh);
            $this->error("Empty CSV.");
            return self::FAILURE;
        }
        $idx = array_flip(array_map(fn($h) => strtolower(trim((string) $h)), $header));
        $required = ['sku', 'name', 'brand', 'active_ingredient', 'is_robot', 'categorie'];
        foreach ($required as $col) {
            if (! isset($idx[$col])) {
                $this->error("Missing column '{$col}' in CSV.");
                fclose($fh);
                return self::FAILURE;
            }
        }

        $rowsByCnk = [];
        while (($row = fgetcsv($fh)) !== false) {
            $cnk = Medication::normalizeCnk($row[$idx['sku']] ?? null);
            if ($cnk === null) {
                continue;
            }
            $rowsByCnk[$cnk][] = $row;
        }
        fclose($fh);

        $imported = 0;
        $skippedUnknown = 0;
        $linked = 0;

        foreach ($rowsByCnk as $cnk => $rows) {
            $first = $rows[0];
            $name = $this->csvVal($first[$idx['name']] ?? null);
            $brand = $this->csvVal($first[$idx['brand']] ?? null);
            $category = $this->csvVal($first[$idx['categorie']] ?? null);
            $isRobot = $this->csvBool($first[$idx['is_robot']] ?? null);

            $ingredients = [];
            foreach ($rows as $r) {
                $ai = $this->csvVal($r[$idx['active_ingredient']] ?? null);
                if ($ai !== null && $ai !== '') {
                    $ingredients[] = $ai;
                }
            }
            $ingredients = array_values(array_unique($ingredients));

            if ($onlyKnown && ! Medication::where('cnk', $cnk)->exists()) {
                $skippedUnknown++;
                continue;
            }

            if ($dry) {
                $imported++;
                $linked += count($ingredients);
                continue;
            }

            DB::transaction(function () use ($cnk, $name, $brand, $category, $isRobot, $ingredients, &$imported, &$linked) {
                $existing = Medication::where('cnk', $cnk)->first();
                $existingBron = $existing?->bron;
                $newBron = match (true) {
                    $existingBron === 'bcfi' => 'medipim+bcfi',
                    $existingBron === 'medipim+bcfi' => 'medipim+bcfi',
                    default => 'medipim',
                };
                $status = count($ingredients) > 0 ? 'verrijkt' : ($existing?->status ?? 'deels-verrijkt');

                $med = Medication::updateOrCreate(
                    ['cnk' => $cnk],
                    [
                        'name' => $name ?? ($existing?->name ?? $cnk),
                        'brand' => $brand ?? $existing?->brand,
                        'category' => $category ?? $existing?->category,
                        'is_robot' => $isRobot,
                        'status' => $status,
                        'bron' => $newBron,
                    ],
                );

                $ingredientIds = [];
                foreach ($ingredients as $ai) {
                    $slug = Slug::make($ai);
                    if ($slug === '') {
                        continue;
                    }
                    $ingredient = ActiveIngredient::firstOrCreate(
                        ['slug' => $slug],
                        ['name' => $ai],
                    );
                    $ingredientIds[$ingredient->id] = true;
                }
                if (count($ingredientIds) > 0) {
                    $med->activeIngredients()->syncWithoutDetaching(array_keys($ingredientIds));
                    $linked += count($ingredientIds);
                }
                $imported++;
            });
        }

        $this->info("Imported/updated medications: {$imported}");
        $this->info("Linked ingredients:          {$linked}");
        if ($onlyKnown) {
            $this->info("Skipped (unknown CNK):       {$skippedUnknown}");
        }
        if ($dry) {
            $this->warn('Dry-run — no changes persisted.');
        }
        return self::SUCCESS;
    }

    private function csvVal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || strtoupper($value) === 'NULL') {
            return null;
        }
        return $value;
    }

    private function csvBool(mixed $value): bool
    {
        $v = $this->csvVal($value);
        return $v !== null && in_array(strtolower($v), ['1', 'true', 'yes'], true);
    }
}
