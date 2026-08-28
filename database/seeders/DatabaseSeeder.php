<?php

use Database\Seeders\RefactorFixtureSeeder;
use Database\Seeders\SeoOpsSystemUserSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            SeoOpsSystemUserSeeder::class,
            RefactorFixtureSeeder::class,
        ]);
    }
}
