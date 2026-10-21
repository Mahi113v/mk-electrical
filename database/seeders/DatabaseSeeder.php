<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // roles, account years, first company and the logins
        $this->call(AdminUserSeeder::class);

        // sample data for every page (companies, products, purchase, sales, orders, stock ...).
        // Local / testing: on by default. Production (Railway): only when SEED_SAMPLE_DATA=true.
        if (filter_var(env('SEED_SAMPLE_DATA', !app()->environment('production')), FILTER_VALIDATE_BOOLEAN)) {
            $this->call(SampleDataSeeder::class);
        }
    }
}
