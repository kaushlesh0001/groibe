<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production-safe seeders only. Demo data lives in DemoSeeder (local/staging, later pass).
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SuperAdminSeeder::class,
            TaxonomySeeder::class,
            FeatureFlagSeeder::class,
            AgreementTemplateSeeder::class,
            WebsiteContentSeeder::class,
        ]);
    }
}
