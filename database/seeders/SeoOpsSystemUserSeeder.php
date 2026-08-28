<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Users\SeoOpsSystemUser;
use Illuminate\Database\Seeder;

final class SeoOpsSystemUserSeeder extends Seeder
{
    public function run(): void
    {
        SeoOpsSystemUser::ensure();
    }
}
