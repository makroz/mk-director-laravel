<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Publishes the base abilities the package expects.
 *
 * Project-specific seeder (e.g. App\Modules\Admin\Database\Seeders\AbilitySeeder)
 * may extend or call this for the default set.
 */
class AbilitySeeder extends Seeder
{
    /**
     * @var list<string>
     */
    protected array $abilities = [
        'users.view',
        'users.create',
        'users.edit',
        'users.delete',
        'users.*',
        'roles.view',
        'roles.create',
        'roles.edit',
        'roles.delete',
        'roles.*',
        'surveys.view',
        'surveys.create',
        'surveys.edit',
        'surveys.delete',
        'surveys.*',
    ];

    public function run(): void
    {
        $now = now();

        foreach ($this->abilities as $ability) {
            DB::table('abilities')->updateOrInsert(
                ['name' => $ability],
                [
                    'description' => ucfirst(str_replace(['.', '_'], ' ', $ability)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
