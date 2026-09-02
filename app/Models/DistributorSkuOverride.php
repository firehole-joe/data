<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The durable review ledger for a distributor listing.
 *
 * A row records a reviewer's decision — "approve at N rounds/unit" or
 * "ignore" — keyed by `distributor_id` + `distributor_sku` and, as a
 * fallback identity, by `upc`. {@see \App\Services\Feeds\DistributorSkuOverrideManager}
 * re-applies the decision on every feed import: an ignored listing
 * bypasses the review quarantine, an approved one is priced against the
 * confirmed `round_count`. The `baseline_*` snapshot lets a later import
 * resurface the listing for review only when its price or packaging has
 * drifted materially since the decision was made.
 *
 * `round_count` is 0 on an ignore-only row that never carried a
 * confirmed count; consult `is_ignored` before trusting it.
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
        'upc',
        'round_count',
        'is_ignored',
        'baseline_price',
        'baseline_description',
        'note',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'round_count' => 'integer',
        'is_ignored' => 'boolean',
        'baseline_price' => 'decimal:4',
    ];

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
