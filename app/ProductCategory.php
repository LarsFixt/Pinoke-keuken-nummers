<?php

namespace App;

/**
 * How products are grouped in the order statistics.
 */
enum ProductCategory: string
{
    case TostiPanini = 'tosti_panini';
    case Sandwich = 'sandwich';
    case Snack = 'snack';
    case Bites = 'bites';
    case Pizza = 'pizza';
    case Meal = 'meal';
    case Kids = 'kids';
    case Drink = 'drink';
    case Staff = 'staff';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TostiPanini => __('Tostis & panini'),
            self::Sandwich => __('Sandwiches'),
            self::Snack => __('Snacks & fries'),
            self::Bites => __('Bar snacks'),
            self::Pizza => __('Pizza'),
            self::Meal => __('Meals'),
            self::Kids => __('Kids'),
            self::Drink => __('Drinks'),
            self::Staff => __('Staff meals'),
            self::Other => __('Other'),
        };
    }

    /**
     * Text colour class for the chart series of this category.
     */
    public function chartTextClass(): string
    {
        return match ($this) {
            self::TostiPanini => 'text-amber-400',
            self::Sandwich => 'text-red-500',
            self::Snack => 'text-sky-400',
            self::Bites => 'text-violet-500',
            self::Pizza => 'text-lime-500',
            self::Meal => 'text-emerald-500',
            self::Kids => 'text-pink-400',
            self::Drink => 'text-fuchsia-300',
            self::Staff => 'text-zinc-400',
            self::Other => 'text-zinc-600',
        };
    }

    /**
     * Background colour class for the legend of this category.
     */
    public function chartBackgroundClass(): string
    {
        return match ($this) {
            self::TostiPanini => 'bg-amber-400',
            self::Sandwich => 'bg-red-500',
            self::Snack => 'bg-sky-400',
            self::Bites => 'bg-violet-500',
            self::Pizza => 'bg-lime-500',
            self::Meal => 'bg-emerald-500',
            self::Kids => 'bg-pink-400',
            self::Drink => 'bg-fuchsia-300',
            self::Staff => 'bg-zinc-400',
            self::Other => 'bg-zinc-600',
        };
    }
}
