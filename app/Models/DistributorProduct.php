<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DistributorProduct extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'distributor_id',
        'master_ammunition_id',
        'distributor_sku',
        'raw_upc',
        'raw_mfr_part_number',
        'raw_description',
        'wholesale_price',
        'cost_per_round',
        'map_price',
        'msrp_price',
        'quantity_available',
        'is_in_stock',
        'needs_review',
        'review_reason',
        'last_feed_update_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'wholesale_price' => 'decimal:2',
        'cost_per_round' => 'decimal:4',
        'map_price' => 'decimal:2',
        'msrp_price' => 'decimal:2',
        'quantity_available' => 'integer',
        'is_in_stock' => 'boolean',
        'needs_review' => 'boolean',
        'last_feed_update_at' => 'datetime',
    ];

    /**
     * The distributor that supplies this listing.
     */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /**
     * The canonical ammunition record this listing is mapped to.
     */
    public function masterAmmunition(): BelongsTo
    {
        return $this->belongsTo(MasterAmmunition::class);
    }

    /**
     * Recorded price/quantity snapshots for this listing.
     */
    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }
}
