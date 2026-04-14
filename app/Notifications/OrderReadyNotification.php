<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class OrderReadyNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Order $order)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * Get the web push representation of the notification.
     */
    public function toWebPush(object $notifiable, object $notification): WebPushMessage
    {
        $url = route('track', ['order' => $this->order->number]);

        return (new WebPushMessage)
            ->title(__('Your order is ready!'))
            ->icon('/android-chrome-192x192.png')
            ->body(__('Number') . ' ' . $this->order->number . ' - ' . __('Come pick up your food!'))
            ->action(__('Track your order'), $url)
            ->options(['TTL' => 60 * 15]) // 15 mins to live
            ->data(['url' => $url, 'order_number' => $this->order->number]);
    }
}
