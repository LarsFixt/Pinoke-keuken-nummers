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

    protected $fillable = ['number', 'status'];

    /**
     * Cast the status attribute to an OrderStatus enum instance.
     */
    protected $casts = [
        'status' => OrderStatus::class,
    ];

    /**
     * Keep the lookup key in sync with the displayed number.
     */
    protected static function booted(): void
    {
        static::saving(function (Order $order): void {
            $order->number_key = self::numberKey((string) $order->number);
        });
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
