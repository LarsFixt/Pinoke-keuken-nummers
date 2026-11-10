<?php

use App\Models\Order;
use App\NotificationTiming;
use App\OrderStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::guest')] class extends Component {
    #[Url(as: 'order')]
    public string $currentNumber = '';

    public bool $orderReady = false;

    /**
     * True once the kitchen has received a ticket for this number.
     */
    public bool $orderReceived = false;

    public string $kitchenStatus = '';

    /**
     * The feedback this visit is filling in, so later answers update the same record.
     */
    #[Locked]
    public ?int $feedbackId = null;

    public ?int $feedbackRating = null;

    public ?string $feedbackTiming = null;

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
            'echo:orders,OrderReceived' => 'checkOrderReceived',
            'echo:orders,OrdersUpdated' => 'refreshTrackingStatus',
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

        if (Order::numberKey($number) === '') {
            return;
        }

        $order = Order::currentOrNewForNumber($number);

        if (! $order->exists) {
            $order->status = OrderStatus::Pending;
            $order->save();
        }

        $this->currentNumber = $order->number;
        $this->syncOrderState($order);
    }

    public function subscribeToPush(string $endpoint, string $publicKey, string $authToken, string $contentEncoding): void
    {
        if (empty($this->currentNumber)) {
            return;
        }

        $order = Order::currentWithNumber($this->currentNumber)->first();

        if ($order) {
            $order->updatePushSubscription($endpoint, $publicKey, $authToken, $contentEncoding);
        }
    }

    public function stopTracking(): void
    {
        $this->currentNumber = '';
        $this->orderReady = false;
        $this->orderReceived = false;
        $this->feedbackId = null;
        $this->feedbackRating = null;
        $this->feedbackTiming = null;
    }

    /**
     * Store an answer from the feedback card. Each answer is saved straight away, so partial feedback is kept too.
     *
     * @param  array{rating?: int, notification_timing?: string, comment?: string|null}  $answers
     * @param  array{wait_minutes?: int|null, notification_mode?: string|null, platform?: string|null, display_mode?: string|null, entry?: string|null}  $context
     */
    public function saveFeedback(array $answers, array $context = []): void
    {
        if (! $this->orderReady || $this->currentNumber === '') {
            return;
        }

        $order = Order::currentWithNumber($this->currentNumber)->first();

        if (! $order) {
            return;
        }

        if (array_key_exists('comment', $answers)) {
            $answers['comment'] = trim((string) $answers['comment']) ?: null;
        }

        $validated = Validator::make([...$context, ...$answers], [
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'notification_timing' => ['sometimes', Rule::enum(NotificationTiming::class)],
            'comment' => ['sometimes', 'nullable', 'string', 'max:280'],
            'wait_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,600'],
            'notification_mode' => ['sometimes', 'nullable', 'string', 'max:32'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:32'],
            'display_mode' => ['sometimes', 'nullable', 'string', 'max:32'],
            'entry' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        $feedback = $order->feedback()->whereKey($this->feedbackId)->first() ?? $order->feedback()->make();
        $feedback->fill($validated)->save();

        $this->feedbackId = $feedback->id;
        $this->feedbackRating = $feedback->rating;
        $this->feedbackTiming = $feedback->notification_timing?->value;
    }

    public function refreshTrackingStatus(): void
    {
        if ($this->currentNumber === '') {
            return;
        }

        $order = Order::currentWithNumber($this->currentNumber)->first();

        if (!$order) {
            return;
        }

        $this->currentNumber = $order->number;
        $this->syncOrderState($order);
    }

    public function checkOrderReady(array $event): void
    {
        if ($this->isTrackedOrder($event)) {
            $this->orderReady = true;
        }
    }

    public function checkOrderReceived(array $event): void
    {
        if ($this->isTrackedOrder($event)) {
            $this->refreshTrackingStatus();
        }
    }

    private function syncOrderState(Order $order): void
    {
        $this->orderReady = $order->status === OrderStatus::Ready;
        $this->orderReceived = $order->kitchenTickets()->exists();
    }

    /**
     * @param  array{order?: array{number?: string}|null}  $event
     */
    private function isTrackedOrder(array $event): bool
    {
        return $this->currentNumber !== ''
            && isset($event['order']['number'])
            && Order::numberKey((string) $event['order']['number']) === Order::numberKey($this->currentNumber);
    }
};
?>

<flux:main class="px-4! py-4! sm:p-6!">
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
        receivedTracked: @js($orderReceived),
        feedbackState: 'open',
        feedbackComment: '',
        readyWaitMinutes: null,
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
                if (this.feedbackHandled($wire.currentNumber)) {
                    this.feedbackState = 'closed';
                }

                await this.closePushNotifications();
            }

            if ($wire.currentNumber && !$wire.orderReady) {
                await this.activateBestNotificationAgent();
                this.trackEvent('track-resume', { mode: this.notificationMode, received: $wire.orderReceived });
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
        trackEvent(name, data = undefined) {
            window.analytics?.track(name, data);
        },
        dismissInstallTip() {
            this.installTipDismissed = true;
            window.localStorage.setItem('track_install_tip_dismissed', '1');
            this.trackEvent('install-tip-dismissed');
        },
        async beginTracking() {
            if (!this.localNumber.length) {
                return;
            }

            await $wire.startWatching(this.localNumber);
            await this.activateBestNotificationAgent();
            if ($wire.currentNumber) {
                window.analytics?.mark('track.' + $wire.currentNumber);
            }
            this.trackEvent('track-start', { mode: this.notificationMode, platform: window.analytics?.platform });
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
            this.trackEvent('notification-permission', { result: this.notificationPermission });
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
                this.trackEvent('push-subscribed');
            } catch (e) {
                this.pushLinkState = 'failed';
                this.trackEvent('push-failed');
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
        orderBecameReady(number) {
            this.notified = true;
            this.readyWaitMinutes = window.analytics?.minutesSince('track.' + number) ?? null;
            this.feedbackState = this.feedbackHandled(number) ? 'closed' : 'open';
            this.trackEvent('order-ready', { mode: this.notificationMode, wait_minutes: this.readyWaitMinutes });
            window.analytics?.forget('track.' + number);
        },
        feedbackKey(number) {
            return 'lf.feedback.' + number;
        },
        feedbackHandled(number) {
            try {
                const handledAt = Number(window.localStorage.getItem(this.feedbackKey(number)));

                return handledAt > 0 && Date.now() - handledAt < {{ \App\Models\Order::NUMBER_REUSE_AFTER_HOURS }} * 3600000;
            } catch (e) {
                return false;
            }
        },
        rememberFeedbackHandled() {
            try {
                window.localStorage.setItem(this.feedbackKey($wire.currentNumber), String(Date.now()));
            } catch (e) {}
        },
        async answerFeedback(answers) {
            this.rememberFeedbackHandled();
            await $wire.saveFeedback(answers, {
                wait_minutes: this.readyWaitMinutes,
                notification_mode: this.notificationMode,
                ...(window.analytics?.sessionProperties() ?? {}),
            });
        },
        async rateExperience(rating) {
            await this.answerFeedback({ rating });
            this.trackEvent('feedback-rating', { rating, mode: this.notificationMode });
        },
        async rateNotification(timing) {
            await this.answerFeedback({ notification_timing: timing });
            this.trackEvent('feedback-notified', { timing, mode: this.notificationMode });
        },
        async finishFeedback() {
            if (this.feedbackComment.trim()) {
                await this.answerFeedback({ comment: this.feedbackComment });
                this.trackEvent('feedback-comment');
            }

            this.feedbackComment = '';
            this.feedbackState = 'done';
        },
        skipFeedback() {
            this.rememberFeedbackHandled();
            this.trackEvent('feedback-dismissed', { answered: $wire.feedbackRating !== null || $wire.feedbackTiming !== null });
            this.feedbackState = 'closed';
        },
        orderBecameReceived() {
            this.receivedTracked = true;
            this.trackEvent('order-received', { mode: this.notificationMode });
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
        x-effect="if (!$wire.currentNumber) { notified = false; receivedTracked = false; feedbackState = 'open'; } if ($wire.orderReceived && !receivedTracked) { orderBecameReceived(); } if ($wire.orderReady && !notified) { orderBecameReady($wire.currentNumber); playSound(); sendNotification($wire.currentNumber); closePushNotifications(); }">
        <div class="mb-4 w-full text-center sm:mb-8">
            <flux:breadcrumbs class="mb-3">
                <flux:breadcrumbs.item href="{{ route('home') }}">{{ __('All orders') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Track order') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:text class="text-2xl font-black tracking-tight uppercase sm:text-3xl">
                {{ __('Track your order') }}
            </flux:text>
            @unless ($currentNumber)
                <flux:text class="mt-1 text-sm sm:mt-2 sm:text-base">
                    {{ __('Enter your number and we will let you know when your food is ready.') }}
                </flux:text>
            @endunless

            @if ($kitchenStatus)
                <flux:callout variant="warning" icon="megaphone" class="my-2" heading="{{ $kitchenStatus }}" />
            @endif
        </div>

        @if ($orderReady)
            {{-- Order ready. Every state block has its own wire:key, so Livewire swaps it whole instead of reusing Alpine elements from the previous state. --}}
            <div wire:key="track-state-ready" class="flex w-full max-w-lg flex-col gap-3 text-center">
                <flux:card class="flex flex-col items-center justify-center gap-1 px-4! py-5! sm:gap-3 sm:py-8!">
                    <flux:icon.check-circle class="size-10 shrink-0 text-green-500 sm:size-14" />
                    <flux:heading size="xl" class="text-green-500! font-bold uppercase">
                        {{ __('Your order is ready!') }}
                    </flux:heading>
                    <flux:text class="text-7xl font-black tracking-tighter sm:text-8xl">
                        {{ $currentNumber }}
                    </flux:text>
                    <flux:text class="text-base sm:text-lg">
                        {{ __('Come pick up your food!') }}
                    </flux:text>
                </flux:card>

                {{-- Feedback --}}
                <flux:card wire:key="track-feedback" x-cloak x-show="feedbackState !== 'closed'" class="px-4! py-4! text-left sm:p-6!">
                    <div x-show="feedbackState === 'open'" class="flex flex-col gap-4">
                        <div>
                            <flux:heading>{{ __('How was ordering and waiting today?') }}</flux:heading>
                            <div class="mt-2 grid grid-cols-5 gap-1.5 sm:gap-2">
                                @foreach ([1 => '😞', 2 => '🙁', 3 => '😐', 4 => '🙂', 5 => '😄'] as $score => $emoji)
                                    <flux:button wire:key="feedback-rating-{{ $score }}" @click="rateExperience({{ $score }})"
                                        :variant="$feedbackRating === $score ? 'primary' : 'filled'" class="h-12 px-0! text-2xl! sm:h-14 sm:text-3xl!"
                                        aria-label="{{ __(':score out of 5', ['score' => $score]) }}">
                                        {{ $emoji }}
                                    </flux:button>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <flux:heading>{{ __('Did we let you know in time that your order was ready?') }}</flux:heading>
                            <div class="mt-2 grid gap-1.5 sm:grid-cols-3 sm:gap-2">
                                @foreach (\App\NotificationTiming::cases() as $timing)
                                    <flux:button wire:key="feedback-timing-{{ $timing->value }}"
                                        @click="rateNotification('{{ $timing->value }}')"
                                        :variant="$feedbackTiming === $timing->value ? 'primary' : 'filled'" size="sm">
                                        {{ $timing->label() }}
                                    </flux:button>
                                @endforeach
                            </div>
                        </div>

                        <div x-show="$wire.feedbackRating !== null || $wire.feedbackTiming !== null" class="flex flex-col gap-2">
                            <flux:input x-model="feedbackComment" maxlength="280"
                                :label="__('Anything we can do better? (optional)')" />
                            <flux:button variant="primary" @click="finishFeedback()">
                                <span x-text="feedbackComment.trim() ? @js(__('Send')) : @js(__('Done'))"></span>
                            </flux:button>
                        </div>

                        <flux:button variant="ghost" size="xs" class="self-center" @click="skipFeedback()">
                            {{ __('Skip') }}
                        </flux:button>
                    </div>

                    <div x-show="feedbackState === 'done'" class="flex items-center gap-3">
                        <flux:icon.heart class="size-6 shrink-0 text-blue-800" variant="solid" />
                        <flux:text class="text-base">{{ __('Thanks for your feedback!') }}</flux:text>
                    </div>
                </flux:card>

                <flux:button wire:click="stopTracking" data-umami-event="track-another" variant="filled" class="w-full">
                    {{ __('Track another order') }}
                </flux:button>
            </div>
        @elseif ($currentNumber)
            {{-- Watching state --}}
            <div wire:key="track-state-watching" class="w-full max-w-lg text-center">
                <flux:card class="flex flex-col items-center justify-center gap-2 px-4! py-6! sm:gap-4 sm:py-10!">
                    <flux:icon.clock class="size-10 animate-pulse sm:size-16" />
                    <flux:text class="text-7xl font-black tracking-tighter sm:text-8xl">
                        {{ $currentNumber }}
                    </flux:text>
                    @if ($orderReceived)
                        <flux:text class="text-lg uppercase tracking-widest font-semibold">
                            {{ __('Your order is being prepared') }}
                        </flux:text>
                        <flux:text class="text-sm">
                            {{ __('The kitchen has your order. We will notify you as soon as it is ready.') }}
                        </flux:text>
                    @else
                        <flux:text class="text-lg uppercase tracking-widest font-semibold">
                            {{ __('Preparing...') }}
                        </flux:text>
                        <flux:text class="text-sm">
                            {{ __('We will notify you as soon as it is ready.') }}
                        </flux:text>
                    @endif
                    <flux:button wire:click="stopTracking" data-umami-event="track-cancel" class="mt-2 w-full sm:mt-4" size="sm">
                        {{ __('Cancel') }}
                    </flux:button>
                </flux:card>

                <div x-cloak x-show="notificationMode === 'permission-needed'" class="mt-3 sm:mt-4">
                    <flux:button @click="requestNotificationPermission()" data-umami-event="notification-allow-click" variant="subtle" class="w-full text-sm"
                        icon="bell" icon-position="left">
                        {{ __('Allow notifications') }}
                    </flux:button>
                </div>

                <flux:callout x-cloak x-show="notificationMode === 'push-ready' && pushLinkState === 'linked'"
                    icon="check-circle" variant="success" class="mt-3 text-left sm:mt-4">
                    <flux:callout.heading>{{ __('Background notifications are active on this device.') }}
                    </flux:callout.heading>
                </flux:callout>

                <flux:callout x-cloak x-show="notificationMode === 'push-ready' && pushLinkState === 'failed'"
                    icon="exclamation-triangle" variant="warning" class="mt-3 text-left sm:mt-4">
                    <flux:callout.heading>{{ __('We could not enable push yet. Keep this page open and try again.') }}
                    </flux:callout.heading>
                    <x-slot name="actions">
                        <flux:button size="sm" variant="filled" @click="subscribeToPush()" data-umami-event="push-retry">
                            {{ __('Try again') }}
                        </flux:button>
                    </x-slot>
                </flux:callout>

                <flux:callout x-cloak x-show="notificationMode === 'push-blocked'" icon="bell-slash" variant="warning"
                    class="mt-3 text-left sm:mt-4">
                    <flux:callout.heading>{{ __('Notifications are blocked in your browser settings for this site.') }}
                    </flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Keep this page open while waiting, or enable notifications in your browser settings.') }}
                    </flux:callout.text>
                </flux:callout>

                <flux:callout x-cloak x-show="notificationMode === 'echo-only-ios'" icon="device-phone-mobile"
                    variant="secondary" class="mt-3 text-left sm:mt-4">
                    <flux:callout.heading>{{ __('iPhone and iPad browsers cannot deliver background push here.') }}
                    </flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Keep this page open while waiting. Tip: tap Share and choose Add to Home Screen for faster return access.') }}
                    </flux:callout.text>
                </flux:callout>

                <flux:callout x-cloak x-show="notificationMode === 'echo-only'" icon="signal" variant="secondary"
                    class="mt-3 text-left sm:mt-4">
                    <flux:callout.heading>{{ __('Live updates are active while this page is open.') }}
                    </flux:callout.heading>
                </flux:callout>
            </div>
        @else
            {{-- Number entry --}}
            <div wire:key="track-state-entry" class="w-full max-w-lg">
                <flux:card class="p-4! sm:p-6!">
                    <div
                        class="mb-4 flex h-16 items-center justify-center rounded-xl bg-gray-100 text-center sm:mb-6 sm:h-20 dark:bg-zinc-800">
                        <span class="text-4xl font-black tracking-wider text-gray-900 sm:text-5xl dark:text-gray-100"
                            x-text="localNumber || '....'">
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 sm:gap-3">
                        @foreach ([7, 8, 9, 4, 5, 6, 1, 2, 3] as $num)
                            <flux:button @click="if (localNumber.length < 4) localNumber += '{{ $num }}'"
                                class="h-14 text-2xl! sm:h-16">
                                {{ $num }}
                            </flux:button>
                        @endforeach

                        <flux:button @click="localNumber = ''" variant="ghost" class="h-14 text-lg! sm:h-16">
                            {{ __('Clear') }}
                        </flux:button>

                        <flux:button @click="if (localNumber.length < 4) localNumber += '0'" class="h-14 text-2xl! sm:h-16">
                            0
                        </flux:button>

                        <div></div>
                    </div>

                    <flux:button variant="primary" class="mt-4 h-12! w-full text-base!" x-bind:disabled="!localNumber.length"
                        @click="beginTracking()">
                        {{ __('Follow my order') }}
                    </flux:button>
                </flux:card>
            </div>
        @endif

        <div class="mt-3 w-full max-w-lg sm:mt-4" x-cloak x-show="!installTipDismissed && !$wire.orderReady">
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

        <flux:text class="mt-3 px-2 text-center sm:mt-4" x-cloak
            x-show="$wire.currentNumber && !$wire.orderReady && (notificationMode === 'echo-only-ios' || notificationMode === 'echo-only' || notificationMode === 'push-blocked')">
            {{ __('Keep this page open to receive notifications on this device.') }}
        </flux:text>

        @if ($currentNumber && !$orderReady)
            <div wire:poll.30s.keep-alive="refreshTrackingStatus" class="hidden"></div>
        @endif
    </div>
</flux:main>
