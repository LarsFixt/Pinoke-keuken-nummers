<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductAliasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another way a product name was read from a ticket, e.g. "losti ham/kaas" for "Tosti ham/kaas".
 */
class ProductAlias extends Model
{
    /** @use HasFactory<ProductAliasFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'alias',
    ];

    /**
     * Get the product this spelling belongs to.
     *
     * @return BelongsTo<Product, covariant $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
