<?php

declare(strict_types=1);

namespace App\Modules\SpecShop\Models;

use App\Modules\SpecShop\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** `status` has its own endpoint: out of `$fillable` on purpose. */
class Product extends Model
{
    protected $table = 'spec_products';

    protected $fillable = ['name', 'price'];

    protected $attributes = ['status' => 1];

    protected $casts = ['price' => 'decimal:2', 'status' => ProductStatus::class];

    /** @return HasMany<Product, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_id');
    }

    /** Not a relation: no Relation return type. */
    public function label(): string
    {
        return (string) $this->name;
    }
}
