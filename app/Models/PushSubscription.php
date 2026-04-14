<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

class PushSubscription extends BasePushSubscription
{
    /**
     * Get the order related to the subscription.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
