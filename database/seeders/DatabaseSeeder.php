<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Bootstrap seeders create minimum required prerequisite records for a
     * fresh clone. They are idempotent and safe to run multiple times.
     *
     * Dependency order:
     *   Company → Modem → Sim (requires Company + optional Modem)
     *                   → ApiClient (requires Company)
     *
     * DemoSmokeTestSeeder is intentionally omitted from the default run.
     * To run it manually: php artisan db:seed --class=DemoSmokeTestSeeder
     */
    public function run(): void
    {
        $this->call([
            BootstrapCompanySeeder::class,
            BootstrapModemSeeder::class,
            BootstrapSimSeeder::class,
            BootstrapApiClientSeeder::class,
        ]);
    }
}
