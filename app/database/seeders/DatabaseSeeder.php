<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'apotheker@farmapunt.be'],
            [
                'name' => 'Apotheker Farmapunt',
                'password' => Hash::make('farmapunt-change-me'),
                'email_verified_at' => now(),
            ],
        );

        $this->call([
            DrugClassIngredientsSeeder::class,
            GheopsSeeder::class,
        ]);
    }
}
