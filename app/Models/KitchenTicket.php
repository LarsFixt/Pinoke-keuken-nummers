<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\KitchenTicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kitchen ticket captured from the POS printer by the ticket bridge Pi.
 */
class KitchenTicket extends Model
{
    /** @use HasFactory<KitchenTicketFactory> */
    use HasFactory;

    /**
     * Below this OCR confidence the kitchen is asked to double-check the number.
     */
    public const float MIN_NUMBER_CONFIDENCE = 75.0;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'external_id',
        'order_id',
        'register',
        'register_name',
        'station',
        'ticket_number',
        'items',
        'raw_text',
        'ocr_confidence',
        'ticket_number_confidence',
        'warnings',
        'printed_at',
        'captured_at',
        'dismissed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'register' => 'integer',
            'items' => 'array',
            'warnings' => 'array',
            'ocr_confidence' => 'float',
            'ticket_number_confidence' => 'float',
            'printed_at' => 'datetime',
            'captured_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /**
     * Get the order this ticket belongs to.
     *
     * @return BelongsTo<Order, covariant $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Tickets without a readable number that the kitchen has not dismissed yet.
     *
     * @param  Builder<KitchenTicket>  $query
     */
    public function scopeNeedsAttention(Builder $query): void
    {
        $query->whereNull('order_id')->whereNull('dismissed_at');
    }

    /**
     * Whether the kitchen should double-check this ticket.
     */
    public function isUncertain(): bool
    {
        return $this->warnings !== []
            || ($this->ticket_number_confidence !== null && $this->ticket_number_confidence < self::MIN_NUMBER_CONFIDENCE);
    }
}
