<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'distributor_product_id',
        'wholesale_price',
        'quantity_available',
        'recorded_date',
    ];

    /**
     * The attributes that should be cast.
     *
     * `recorded_date` is deliberately left uncast: it is a calendar day
     * (not a moment) used as part of a unique key, so it is stored and
     * compared as a plain `Y-m-d` string across every database driver.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'wholesale_price' => 'decimal:2',
        'quantity_available' => 'integer',
    ];

    /**
     * The distributor listing this snapshot belongs to.
     */
    public function distributorProduct(): BelongsTo
    {
        return $this->belongsTo(DistributorProduct::class);
    }
}
