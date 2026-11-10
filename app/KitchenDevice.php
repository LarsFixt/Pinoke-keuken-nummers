<?php

namespace App;

/**
 * The kitchen device a product is made on, e.g. tostis and panini on the grill.
 */
enum KitchenDevice: string
{
    case Grill = 'grill';
    case Fryer = 'fryer';
    case PizzaOven = 'pizza_oven';
    case Stove = 'stove';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Grill => __('Grill'),
            self::Fryer => __('Deep fryer'),
            self::PizzaOven => __('Pizza oven'),
            self::Stove => __('Stove'),
            self::None => __('No device'),
        };
    }

    /**
     * How long the device needs to heat up, so it can be switched on before the first order comes in.
     */
    public function warmUpMinutes(): int
    {
        return match ($this) {
            self::Grill => 10,
            self::Fryer => 20,
            self::PizzaOven => 30,
            self::Stove => 10,
            self::None => 0,
        };
    }
}
