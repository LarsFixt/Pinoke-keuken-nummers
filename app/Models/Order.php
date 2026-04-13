<?php

namespace App\Models;

use App\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = ['number', 'status'];

    /**
     * Cast the status attribute to an OrderStatus enum instance.
     */
    protected $casts = [
        'status' => OrderStatus::class,
    ];

    /**
     * Scope a query to only include ready orders.
     */
    public function scopeReady($query)
    {
        return $query->where('status', OrderStatus::Ready);
    }

    /**
     * Mark the order as completed.
     */
    public function markCompleted(): void
    {
        $this->update(['status' => OrderStatus::Completed]);
    }
}
