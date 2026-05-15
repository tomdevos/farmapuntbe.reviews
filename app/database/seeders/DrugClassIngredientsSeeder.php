<?php

namespace Database\Seeders;

use App\Models\ActiveIngredient;
use App\Models\DrugClassIngredient;
use Illuminate\Database\Seeder;

class DrugClassIngredientsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/drug-class-ingredients.json');
        if (! file_exists($path)) {
            $this->command->warn("Skipping DrugClassIngredientsSeeder: {$path} not found");
            return;
        }
        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            $this->command->error("Could not decode drug-class-ingredients.json");
            return;
        }

        $count = 0;
        foreach ($rows as $row) {
            $ingredient = ActiveIngredient::firstOrCreate(
                ['slug' => $row['ingredient_slug']],
                ['name' => $row['ingredient_name']],
            );
            DrugClassIngredient::firstOrCreate([
                'drug_class' => $row['drug_class'],
                'active_ingredient_id' => $ingredient->id,
            ]);
            $count++;
        }

        $this->command->info("DrugClassIngredientsSeeder: {$count} rows seeded");
    }
}
