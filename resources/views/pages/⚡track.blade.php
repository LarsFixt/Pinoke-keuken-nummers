<?php

use App\Models\Order;
use App\OrderStatus;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::guest')] class extends Component {
    #[Url(as: 'order')]
    public string $currentNumber = '';

    public bool $orderReady = false;

    public string $kitchenStatus = '';

    public function mount(): void
    {
        $this->kitchenStatus = cache('kitchen_status', '');

        if ($this->currentNumber) {
            $this->startWatching($this->currentNumber);
        }
    }

    public function getListeners(): array
    {
        return [
            'echo:orders,OrderReady' => 'checkOrderReady',
            'echo:orders,KitchenStatusUpdated' => 'updateStatus',
        ];
    }

    public function updateStatus(array $event): void
    {
        $this->kitchenStatus = $event['message'] ?? '';
    }

    public function startWatching(string $number): void
    {
        $number = trim($number);

        if (empty($number) || !preg_match('/^[0-9]{1,4}$/', $number)) {
            return;
        }

        $this->currentNumber = $number;

        $order = Order::firstOrCreate(['number' => $this->currentNumber], ['status' => OrderStatus::Pending]);
        $this->orderReady = $order->status->value === 'ready';
    }

    public function subscribeToPush(string $endpoint, string $publicKey, string $authToken, string $contentEncoding): void
    {
        if (empty($this->currentNumber)) {
            return;
        }

        $order = Order::where('number', $this->currentNumber)->first();

        if ($order) {
            $order->updatePushSubscription($endpoint, $publicKey, $authToken, $contentEncoding);
        }
    }

    public function stopTracking(): void
    {
        $this->currentNumber = '';
        $this->orderReady = false;
    }

    public function refreshTrackingStatus(): void
    {
        if ($this->currentNumber === '') {
            return;
        }

        $order = Order::where('number', $this->currentNumber)->first();

        if (!$order) {
            return;
        }

        $this->orderReady = $order->status->value === 'ready';
    }

    public function checkOrderReady(array $event): void
    {
        if ($this->currentNumber && isset($event['order']['number']) && (string) $event['order']['number'] === (string) $this->currentNumber) {
            $this->orderReady = true;
        }
    }
};
?>

<flux:main>
    @push('meta')
        <meta name="description"
            content="{{ __('Enter your order number and receive a notification the moment your food is ready for pick-up.') }}">
    @endpush
    @push('og')
        <meta property="og:description"
            content="{{ __('Enter your order number and receive a notification the moment your food is ready for pick-up.') }}">
    @endpush
    @push('twitter')
        <meta name="twitter:description"
            content="{{ __('Enter your order number and receive a notification the moment your food is ready for pick-up.') }}">
    @endpush
    <div class="flex flex-col items-center justify-center" x-data="{
        notified: @js($orderReady),
        localNumber: '',
        notificationPermission: 'unsupported',
        notificationMode: 'echo-only',
        pushLinkState: 'idle',
        supportsNotifications: false,
        supportsPush: false,
        isIos: false,
        isStandalone: false,
        installTipDismissed: false,
        backgroundRefreshInterval: null,
        async init() {
            const userAgent = window.navigator.userAgent || '';
            const platform = window.navigator.platform || '';
    
            this.isIos = /iPad|iPhone|iPod/.test(userAgent) || (platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);
            this.isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            this.supportsNotifications = 'Notification' in window;
            this.supportsPush = 'serviceWorker' in navigator && 'PushManager' in window;
            this.notificationPermission = this.supportsNotifications ? Notification.permission : 'unsupported';
            this.installTipDismissed = window.localStorage.getItem('track_install_tip_dismissed') === '1';
    
            this.updateNotificationMode();
            this.setupRefreshFallbacks();
    
            if (this.notified) {
                await this.closePushNotifications();
            }
    
            if ($wire.currentNumber && !$wire.orderReady) {
                await this.activateBestNotificationAgent();
            }
        },
        setupRefreshFallbacks() {
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && $wire.currentNumber && !$wire.orderReady) {
                    $wire.refreshTrackingStatus();
                }
            });
    
            window.addEventListener('online', () => {
                if ($wire.currentNumber && !$wire.orderReady) {
                    $wire.refreshTrackingStatus();
                }
            });
    
            this.backgroundRefreshInterval = window.setInterval(() => {
                if ($wire.currentNumber && !$wire.orderReady) {
                    $wire.refreshTrackingStatus();
                }
            }, 30000);
        },
        updateNotificationMode() {
            this.notificationPermission = this.supportsNotifications ? Notification.permission : 'unsupported';
    
            if (this.isIos) {
                this.notificationMode = 'echo-only-ios';
                this.pushLinkState = 'unsupported';
    
                return;
            }
    
            if (!this.supportsNotifications || !this.supportsPush) {
                this.notificationMode = 'echo-only';
                this.pushLinkState = 'unsupported';
    
                return;
            }
    
            if (this.notificationPermission === 'granted') {
                this.notificationMode = 'push-ready';
    
                return;
            }
    
            if (this.notificationPermission === 'denied') {
                this.notificationMode = 'push-blocked';
    
                return;
            }
    
            this.notificationMode = 'permission-needed';
        },
        dismissInstallTip() {
            this.installTipDismissed = true;
            window.localStorage.setItem('track_install_tip_dismissed', '1');
        },
        async beginTracking() {
            if (!this.localNumber.length) {
                return;
            }
    
            await $wire.startWatching(this.localNumber);
            await this.activateBestNotificationAgent();
            this.localNumber = '';
        },
        async activateBestNotificationAgent() {
            this.updateNotificationMode();
    
            if (this.notificationMode === 'permission-needed') {
                await this.requestNotificationPermission();
            }
    
            if (this.notificationMode === 'push-ready') {
                await this.subscribeToPush();
            }
        },
        async requestNotificationPermission() {
            if (!this.supportsNotifications || this.isIos || this.notificationPermission !== 'default') {
                this.updateNotificationMode();
    
                return;
            }
    
            this.notificationPermission = await Notification.requestPermission();
            this.updateNotificationMode();
        },
        async sendNotification(number) {
            if (!('Notification' in window) || Notification.permission !== 'granted') return;
            const title = '{{ __('Your order is ready!') }}';
            const options = {
                body: '{{ __('Number') }} ' + number + ' - {{ __('Come pick up your food!') }}',
                icon: '/android-chrome-192x192.png',
                badge: '/android-chrome-192x192.png',
                vibrate: [200, 100, 200, 100, 200],
            };
            if ('serviceWorker' in navigator) {
                const reg = await navigator.serviceWorker.ready;
                await reg.showNotification(title, options);
            } else {
                new Notification(title, options);
            }
        },
        async subscribeToPush() {
            if (!this.supportsPush || this.notificationMode !== 'push-ready') {
                this.pushLinkState = 'unsupported';
    
                return;
            }
    
            this.pushLinkState = 'linking';
    
            try {
                const reg = await navigator.serviceWorker.ready;
                const vapidPublicKey = '{{ config('webpush.vapid.public_key') }}';
                if (!vapidPublicKey) {
                    this.pushLinkState = 'failed';
    
                    return;
                }
    
                const convertedVapidKey = this.urlBase64ToUint8Array(vapidPublicKey);
                let subscription = await reg.pushManager.getSubscription();
    
                if (!subscription) {
                    subscription = await reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: convertedVapidKey
                    });
                }
    
                const key = subscription.getKey('p256dh');
                const token = subscription.getKey('auth');
    
                if (!key || !token) {
                    this.pushLinkState = 'failed';
    
                    return;
                }
    
                const contentEncoding = (PushManager.supportedContentEncodings || ['aesgcm'])[0] || 'aesgcm';
    
                const endpoint = subscription.endpoint;
                const publicKey = btoa(String.fromCharCode.apply(null, new Uint8Array(key)));
                const authToken = btoa(String.fromCharCode.apply(null, new Uint8Array(token)));
    
                $wire.subscribeToPush(endpoint, publicKey, authToken, contentEncoding);
                this.pushLinkState = 'linked';
            } catch (e) {
                this.pushLinkState = 'failed';
                console.error('Push registration failed:', e);
            }
        },
        urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        },
        playSound() {
            new Audio('/sound/bell.mp3').play();
        },
        async closePushNotifications() {
            if (!('serviceWorker' in navigator)) return;
            const reg = await navigator.serviceWorker.ready;
            const notifications = await reg.getNotifications();
            notifications.forEach(n => n.close());
        },
    }"
        x-effect="if ($wire.orderReady && !notified) { notified = true; playSound(); sendNotification($wire.currentNumber); closePushNotifications(); }">
        <div class="text-center mb-8 w-full">
            <flux:breadcrumbs class="mb-4">
                <flux:breadcrumbs.item href="{{ route('home') }}">{{ __('All orders') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Track order') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:text class="text-3xl font-black tracking-tight uppercase">
                {{ __('Track your order') }}
            </flux:text>
            <flux:text class="text-base mt-2">
                {{ __('Enter your number and we will let you know when your food is ready.') }}
            </flux:text>

            @if ($kitchenStatus)
                <flux:callout variant="warning" icon="megaphone" class="my-2" heading="{{ $kitchenStatus }}" />
            @endif
        </div>

        @if ($orderReady)
            {{-- Order ready --}}
            <div class="w-full max-w-lg text-center">
                <flux:card class="flex flex-col items-center justify-center gap-4 py-10">
                    <flux:icon.check-circle class="w-20 h-20 text-green-500" />
                    <flux:text class="text-8xl font-black tracking-tighter">
                        {{ $currentNumber }}
                    </flux:text>
                    <flux:heading size="xl" class="text-green-500 uppercase font-bold">
                        {{ __('Your order is ready!') }}
                    </flux:heading>
                    <flux:text class="text-lg">
                        {{ __('Come pick up your food!') }}
                    </flux:text>
                    <flux:button wire:click="stopTracking" variant="filled" class="mt-4 w-full">
                        {{ __('Track another order') }}
                    </flux:button>
                </flux:card>
            </div>
        @elseif ($currentNumber)
            {{-- Watching state --}}

            <div class="w-full max-w-lg text-center">
                <flux:card class="flex flex-col items-center justify-center gap-4 py-10">
                    <flux:icon.clock class="w-16 h-16 animate-pulse" />
                    <flux:text class="text-8xl font-black tracking-tighter">
                        {{ $currentNumber }}
                    </flux:text>
                    <flux:text class="text-lg uppercase tracking-widest font-semibold">
                        {{ __('Preparing...') }}
                    </flux:text>
                    <flux:text class="text-sm">
                        {{ __('We will notify you as soon as it is ready.') }}
                    </flux:text>
                    <flux:button wire:click="stopTracking" class="mt-4 w-full" size="sm">
                        {{ __('Cancel') }}
                    </flux:button>
                </flux:card>

                <div x-cloak x-show="notificationMode === 'permission-needed'" class="mt-4">
                    <flux:button @click="requestNotificationPermission()" variant="subtle" class="w-full text-sm"
                        icon="bell" icon-position="left">
                        {{ __('Allow notifications') }}
                    </flux:button>
                </div>

                <flux:callout x-cloak x-show="notificationMode === 'push-ready' && pushLinkState === 'linked'"
                    icon="check-circle" variant="success" class="mt-4 text-left">
                    <flux:callout.heading>{{ __('Background notifications are active on this device.') }}
                    </flux:callout.heading>
                </flux:callout>

                <flux:callout x-cloak x-show="notificationMode === 'push-ready' && pushLinkState === 'failed'"
                    icon="exclamation-triangle" variant="warning" class="mt-4 text-left">
                    <flux:callout.heading>{{ __('We could not enable push yet. Keep this page open and try again.') }}
                    </flux:callout.heading>
                    <x-slot name="actions">
                        <flux:button size="sm" variant="filled" @click="subscribeToPush()">
                            {{ __('Try again') }}
                        </flux:button>
                    </x-slot>
                </flux:callout>

                <flux:callout x-cloak x-show="notificationMode === 'push-blocked'" icon="bell-slash" variant="warning"
                    class="mt-4 text-left">
                    <flux:callout.heading>{{ __('Notifications are blocked in your browser settings for this site.') }}
                    </flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Keep this page open while waiting, or enable notifications in your browser settings.') }}
                    </flux:callout.text>
                </flux:callout>

                <flux:callout x-cloak x-show="notificationMode === 'echo-only-ios'" icon="device-phone-mobile"
                    variant="secondary" class="mt-4 text-left">
                    <flux:callout.heading>{{ __('iPhone and iPad browsers cannot deliver background push here.') }}
                    </flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Keep this page open while waiting. Tip: tap Share and choose Add to Home Screen for faster return access.') }}
                    </flux:callout.text>
                </flux:callout>

                <flux:callout x-cloak x-show="notificationMode === 'echo-only'" icon="signal" variant="secondary"
                    class="mt-4 text-left">
                    <flux:callout.heading>{{ __('Live updates are active while this page is open.') }}
                    </flux:callout.heading>
                </flux:callout>
            </div>
        @else
            {{-- Number entry --}}
            <div class="w-full max-w-lg">
                <flux:card>
                    <div
                        class="text-center mb-6 h-20 flex items-center justify-center bg-gray-100 rounded-xl dark:bg-zinc-800">
                        <span class="text-5xl font-black text-gray-900 dark:text-gray-100 tracking-wider"
                            x-text="localNumber || '...'">
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        @foreach ([7, 8, 9, 4, 5, 6, 1, 2, 3] as $num)
                            <flux:button @click="if (localNumber.length < 4) localNumber += '{{ $num }}'"
                                class="h-16 text-2xl!">
                                {{ $num }}
                            </flux:button>
                        @endforeach

                        <flux:button @click="localNumber = ''" variant="ghost" class="h-16 text-lg!">
                            {{ __('Clear') }}
                        </flux:button>

                        <flux:button @click="if (localNumber.length < 4) localNumber += '0'" class="h-16 text-2xl!">
                            0
                        </flux:button>

                        <div></div>
                    </div>

                    <flux:button variant="primary" class="w-full mt-4" x-bind:disabled="!localNumber.length"
                        @click="beginTracking()">
                        {{ __('Follow my order') }}
                    </flux:button>
                </flux:card>
            </div>
        @endif

        <div class="w-full max-w-lg mt-4" x-cloak x-show="!installTipDismissed">
            <flux:callout icon="device-phone-mobile" variant="secondary" class="text-left">
                <flux:callout.heading x-show="isIos">
                    {{ __('Best on iPhone: keep this page open and add it to your Home Screen.') }}
                </flux:callout.heading>
                <flux:callout.heading x-show="!isIos">
                    {{ __('Best experience: allow notifications and add this app to your Home Screen.') }}
                </flux:callout.heading>

                <flux:callout.text x-show="isIos">
                    {{ __('On iPhone: tap the Share button, then tap Add to Home Screen. Open this page before your order is called.') }}
                </flux:callout.text>
                <flux:callout.text x-show="!isIos">
                    {{ __('On Android and desktop, browser notifications can alert you even when this tab is not in front.') }}
                </flux:callout.text>

                <x-slot name="controls">
                    <flux:button icon="x-mark" variant="ghost" x-on:click="dismissInstallTip()" />
                </x-slot>
            </flux:callout>
        </div>

        <flux:text class="mt-4 px-2 text-center"
            x-show="notificationMode === 'echo-only-ios' || notificationMode === 'echo-only' || notificationMode === 'push-blocked'">
            {{ __('Keep this page open to receive notifications on this device.') }}
        </flux:text>

        @if ($currentNumber && !$orderReady)
            <div wire:poll.30s.keep-alive="refreshTrackingStatus" class="hidden"></div>
        @endif
    </div>
</flux:main>
