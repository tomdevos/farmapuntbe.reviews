<?php

namespace Database\Seeders;

use App\Models\ActiveIngredient;
use App\Models\GheopsCriterion;
use App\Models\GheopsCriterionGroup;
use App\Support\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GheopsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/gheops-criteria.json');
        if (! file_exists($path)) {
            $this->command->warn("Skipping GheopsSeeder: {$path} not found");
            return;
        }
        $criteria = json_decode((string) file_get_contents($path), true);
        if (! is_array($criteria)) {
            $this->command->error("Could not decode gheops-criteria.json");
            return;
        }

        $count = 0;
        foreach ($criteria as $row) {
            $criterion = GheopsCriterion::updateOrCreate(
                ['list_num' => $row['list_num'], 'nr' => $row['nr']],
                [
                    'title' => $row['title'],
                    'comorbiditeit' => $row['comorbiditeit'] ?? null,
                    'rationale' => $row['rationale'] ?? null,
                    'alternatief' => $row['alternatief'] ?? null,
                ],
            );

            $ingredientIds = [];
            foreach ($row['ingredient_slugs'] ?? [] as $slug) {
                $slug = Slug::make($slug);
                if ($slug === '') {
                    continue;
                }
                $ingredient = ActiveIngredient::firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $slug],
                );
                $ingredientIds[$ingredient->id] = true;
            }
            $criterion->activeIngredients()->sync(array_keys($ingredientIds));

            $criterion->groups()->delete();
            foreach (array_unique($row['drug_classes'] ?? []) as $klass) {
                if (! is_string($klass) || $klass === '') {
                    continue;
                }
                GheopsCriterionGroup::create([
                    'gheops_criterion_id' => $criterion->id,
                    'drug_class' => $klass,
                ]);
            }
            $count++;
        }

        $this->command->info("GheopsSeeder: {$count} criteria seeded");
    }
}
