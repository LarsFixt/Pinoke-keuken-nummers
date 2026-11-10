<?php

declare(strict_types=1);

namespace App\Models;

use App\KitchenDevice;
use App\ProductCategory;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product as the POS prints it on kitchen tickets, e.g. "Tosti ham/kaas".
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'category',
        'device',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ProductCategory::class,
            'device' => KitchenDevice::class,
        ];
    }

    /**
     * Other spellings, usually OCR misreadings, that mean this product.
     *
     * @return HasMany<ProductAlias, covariant $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAlias::class);
    }

    /**
     * Get the ticket lines for this product.
     *
     * @return HasMany<OrderItem, covariant $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
