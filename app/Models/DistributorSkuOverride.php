<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewer-confirmed rounds-per-unit count for one distributor SKU,
 * re-applied by {@see \App\Services\Feeds\FeedIngestionService} on every
 * subsequent import so a corrected offering never re-enters the review
 * queue for the same parse fault.
 */
class DistributorSkuOverride extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'distributor_id',
        'distributor_sku',
        'round_count',
        'note',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'round_count' => 'integer',
    ];

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
