<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterAmmunition extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_ammunition';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'upc',
        'mfr_part_number',
        'manufacturer',
        'name',
        'caliber',
        'bullet_weight_gr',
        'bullet_type',
        'case_material',
        'rounds_per_box',
        'is_tracked_in_report',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'bullet_weight_gr' => 'integer',
        'rounds_per_box' => 'integer',
        'is_tracked_in_report' => 'boolean',
    ];

    /**
     * Every distributor listing mapped to this canonical SKU.
     */
    public function distributorProducts(): HasMany
    {
        return $this->hasMany(DistributorProduct::class);
    }

    /**
     * In-stock distributor listings, cheapest wholesale price first.
     */
    public function inStockListings(): HasMany
    {
        return $this->hasMany(DistributorProduct::class)
            ->where('is_in_stock', true)
            ->orderBy('wholesale_price');
    }

    /**
     * The cheapest in-stock distributor listing, if any.
     */
    public function getBestWholesaleListingAttribute(): ?DistributorProduct
    {
        return $this->inStockListings->first();
    }

    /**
     * Sum of available quantity across all in-stock distributor listings.
     */
    public function getTotalAvailableInventoryAttribute(): int
    {
        return (int) $this->inStockListings->sum('quantity_available');
    }
}
