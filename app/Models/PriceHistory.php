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
     * @var array<string, string>
     */
    protected $casts = [
        'wholesale_price' => 'decimal:2',
        'quantity_available' => 'integer',
        'recorded_date' => 'date',
    ];

    /**
     * The distributor listing this snapshot belongs to.
     */
    public function distributorProduct(): BelongsTo
    {
        return $this->belongsTo(DistributorProduct::class);
    }
}
