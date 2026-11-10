<?php

namespace App;

/**
 * How a guest says they heard that their order was ready.
 */
enum NotificationTiming: string
{
    case OnTime = 'on_time';
    case Late = 'late';
    case Missed = 'missed';

    public function label(): string
    {
        return match ($this) {
            self::OnTime => __('Yes, right away'),
            self::Late => __('Late'),
            self::Missed => __('No, I saw it myself'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OnTime => 'green',
            self::Late => 'amber',
            self::Missed => 'red',
        };
    }
}
