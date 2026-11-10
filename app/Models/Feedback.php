<?php

namespace App\Models;

use App\NotificationTiming;
use Database\Factories\FeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest's answer to the feedback card shown when their order is ready.
 */
class Feedback extends Model
{
    /** @use HasFactory<FeedbackFactory> */
    use HasFactory;

    protected $table = 'feedback';

    protected $fillable = [
        'rating',
        'notification_timing',
        'comment',
        'wait_minutes',
        'notification_mode',
        'platform',
        'display_mode',
        'entry',
    ];

    protected $casts = [
        'rating' => 'integer',
        'notification_timing' => NotificationTiming::class,
        'wait_minutes' => 'integer',
    ];

    /**
     * Get the order the feedback is about.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
