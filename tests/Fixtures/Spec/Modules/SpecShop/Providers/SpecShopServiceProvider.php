<?php

declare(strict_types=1);

namespace App\Modules\SpecShop\Providers;

use Illuminate\Support\ServiceProvider;

class SpecShopServiceProvider extends ServiceProvider
{
    /** @return array<int, array{name: string, description?: string, baseline?: bool}> */
    public function discoverAbilities(): array
    {
        return [
            ['name' => 'admin.spec_shop.update', 'description' => 'Editar productos.'],
            ['name' => 'member.spec_shop.viewAny', 'description' => 'Ver productos.', 'baseline' => true],
        ];
    }
}
