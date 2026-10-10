<?php

namespace Database\Seeders;

use App\Enums\FeatureFlag as Flag;
use App\Models\FeatureFlag;
use Illuminate\Database\Seeder;

class FeatureFlagSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Flag::cases() as $flag) {
            FeatureFlag::query()->firstOrCreate(
                ['key' => $flag->value],
                ['enabled' => $flag->default(), 'notes' => $flag->notes()],
            );
        }
    }
}
