<?php

namespace App\Models;

use App\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, Notifiable;

    /**
     * After this many hours the POS may hand out the same number again, so a ticket starts a new order.
     */
    public const int NUMBER_REUSE_AFTER_HOURS = 3;

    protected $fillable = ['number', 'status', 'ordered_at'];

    /**
     * Cast the status attribute to an OrderStatus enum instance.
     */
    protected $casts = [
        'status' => OrderStatus::class,
        'ordered_at' => 'datetime',
        'ready_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Keep the lookup key in sync with the displayed number and record when the status changed.
     */
    protected static function booted(): void
    {
        static::saving(function (Order $order): void {
            $order->number_key = self::numberKey((string) $order->number);

            if (! $order->isDirty('status')) {
                return;
            }

            if ($order->status === OrderStatus::Completed) {
                $order->completed_at = now();

                return;
            }

            // Also when a pick-up is undone, so the order gets a fresh 30 minutes on the display.
            if ($order->status === OrderStatus::Ready && ! $order->isDirty('ready_at')) {
                $order->ready_at = now();
            }

            $order->completed_at = null;
        });
    }

    /**
     * The order that currently owns this number, or a new one when the number is free or has been reused.
     * Older orders with the number that were never completed are closed, so they do not linger on the display.
     */
    public static function currentOrNewForNumber(string $number): Order
    {
        $order = self::currentWithNumber($number)->lockForUpdate()->first();

        if ($order) {
            return $order;
        }

        self::matchingNumber($number)
            ->where('status', '!=', OrderStatus::Completed)
            ->update(['status' => OrderStatus::Completed, 'completed_at' => now()]);

        return new Order(['number' => $number]);
    }

    /**
     * The number used for matching: digits without leading zeros, so "0317" and "317" are the same order.
     */
    public static function numberKey(string $number): string
    {
        return ltrim(trim($number), '0');
    }

    /**
     * Scope a query to the order with this number, with or without leading zeros.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeMatchingNumber(Builder $query, string $number): void
    {
        $query->where('number_key', self::numberKey($number));
    }

    /**
     * Scope a query to the latest order with this number that is recent enough to not be a reused number.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeCurrentWithNumber(Builder $query, string $number): void
    {
        $query->matchingNumber($number)
            ->where('created_at', '>', now()->subHours(self::NUMBER_REUSE_AFTER_HOURS))
            ->latest('id');
    }

    /**
     * Scope a query to orders the kitchen received a ticket for but has not called yet.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeInPreparation(Builder $query): void
    {
        $query->where('status', OrderStatus::Pending)->whereHas('kitchenTickets');
    }

    /**
     * Get the kitchen tickets printed for this order.
     */
    public function kitchenTickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class);
    }

    /**
     * Get the items from all tickets printed for this order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Whether any ticket for this order should be double-checked by the kitchen.
     */
    public function hasUncertainTicket(): bool
    {
        return $this->kitchenTickets->contains(fn (KitchenTicket $ticket): bool => $ticket->isUncertain());
    }

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

    /**
     * Get all of the subscriptions.
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(config('webpush.model'), 'order_id');
    }

    /**
     * Update (or create) subscription.
     */
    public function updatePushSubscription(string $endpoint, ?string $key = null, ?string $token = null, ?string $contentEncoding = null): PushSubscription
    {
        $subscription = app(config('webpush.model'))->findByEndpoint($endpoint);

        if ($subscription && $this->ownsPushSubscription($subscription)) {
            $subscription->public_key = $key;
            $subscription->auth_token = $token;
            $subscription->content_encoding = $contentEncoding;
            $subscription->save();

            return $subscription;
        }

        if ($subscription && ! $this->ownsPushSubscription($subscription)) {
            $subscription->delete();
        }

        return $this->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'public_key' => $key,
            'auth_token' => $token,
            'content_encoding' => $contentEncoding,
        ]);
    }

    /**
     * Determine if the model owns the given subscription.
     */
    public function ownsPushSubscription(PushSubscription $subscription): bool
    {
        return (string) $subscription->order_id === (string) $this->getKey();
    }

    /**
     * Delete subscription by endpoint.
     */
    public function deletePushSubscription(string $endpoint): void
    {
        $this->pushSubscriptions()->where('endpoint', $endpoint)->delete();
    }

    /**
     * Get all of the subscriptions for WebPush notifications.
     */
    public function routeNotificationForWebPush(): Collection
    {
        return $this->pushSubscriptions;
    }
}
