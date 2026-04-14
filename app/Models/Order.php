<?php

namespace App\Models;

use App\OrderStatus;
use Database\Factories\OrderFactory;
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
