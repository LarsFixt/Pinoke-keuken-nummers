<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Last reported status of the two Raspberry Pis, and the commands waiting for the ticket bridge.
 *
 * The kiosk Pi (TV) reports over its bearer token, the bridge Pi over its signed heartbeat.
 * The bridge accepts no incoming connections, so its commands ride along on the heartbeat response.
 */
class PiStatus
{
    public const string BRIDGE_LAST_SEEN_KEY = 'ticket_bridge_last_seen';

    public const string BRIDGE_STATUS_KEY = 'ticket_bridge_status';

    public const string BRIDGE_COMMAND_KEY = 'ticket_bridge_command';

    public const string KIOSK_STATUS_KEY = 'kiosk_status';

    /**
     * Commands the bridge understands.
     *
     * @var list<string>
     */
    public const array BRIDGE_COMMANDS = ['restart', 'reboot'];

    /**
     * A Pi that has not reported for this long is shown as offline.
     */
    public const int OFFLINE_AFTER_SECONDS = 180;

    /**
     * @param  array<string, mixed>  $status
     */
    public function recordKiosk(array $status): void
    {
        Cache::forever(self::KIOSK_STATUS_KEY, [...$status, 'reported_at' => now()->getTimestamp()]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function kiosk(): ?array
    {
        return Cache::get(self::KIOSK_STATUS_KEY);
    }

    public function recordBridgeSeen(): void
    {
        Cache::forever(self::BRIDGE_LAST_SEEN_KEY, now()->getTimestamp());
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public function recordBridge(array $status): void
    {
        $this->recordBridgeSeen();
        Cache::forever(self::BRIDGE_STATUS_KEY, [...$status, 'reported_at' => now()->getTimestamp()]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function bridge(): ?array
    {
        $status = Cache::get(self::BRIDGE_STATUS_KEY);
        $lastSeen = Cache::get(self::BRIDGE_LAST_SEEN_KEY);

        if ($status === null && $lastSeen === null) {
            return null;
        }

        // Ticket deliveries also count as a sign of life, not only heartbeats.
        return [...($status ?? []), 'reported_at' => max((int) ($status['reported_at'] ?? 0), (int) $lastSeen)];
    }

    /**
     * True when the bridge has been seen before but has gone quiet.
     */
    public function bridgeWentQuiet(): bool
    {
        $lastSeen = Cache::get(self::BRIDGE_LAST_SEEN_KEY);

        return $lastSeen !== null && now()->getTimestamp() - (int) $lastSeen > self::OFFLINE_AFTER_SECONDS;
    }

    /**
     * @param  array<string, mixed>|null  $status
     */
    public function isOnline(?array $status): bool
    {
        return $status !== null && now()->getTimestamp() - (int) ($status['reported_at'] ?? 0) <= self::OFFLINE_AFTER_SECONDS;
    }

    public function queueBridgeCommand(string $command): void
    {
        Cache::forever(self::BRIDGE_COMMAND_KEY, $command);
    }

    public function pendingBridgeCommand(): ?string
    {
        return Cache::get(self::BRIDGE_COMMAND_KEY);
    }

    /**
     * Hand the waiting command to the bridge, once.
     */
    public function takeBridgeCommand(): ?string
    {
        return Cache::pull(self::BRIDGE_COMMAND_KEY);
    }
}
