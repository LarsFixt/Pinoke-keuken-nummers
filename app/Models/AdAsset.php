<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AdAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdAsset extends Model
{
    /** @use HasFactory<AdAssetFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sponsor_id',
        'file_path',
        'target_screen',
        'is_vertical',
        'duration_seconds',
        'frequency_weight',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_vertical' => 'boolean',
            'duration_seconds' => 'integer',
            'frequency_weight' => 'integer',
        ];
    }

    /**
     * Get the sponsor that owns the ad asset.
     *
     * @return BelongsTo<Sponsor, covariant $this>
     */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Sponsor::class);
    }
}
