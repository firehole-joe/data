<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Distributor extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'driver_class',
        'transport_type',
        'connection_settings',
        'field_mappings',
        'is_active',
        'sync_frequency',
        'last_synced_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'connection_settings' => 'encrypted:array',
        'field_mappings' => 'array',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /**
     * All catalog listings ingested for this distributor.
     */
    public function distributorProducts(): HasMany
    {
        return $this->hasMany(DistributorProduct::class);
    }

    /**
     * Ingestion run history for this distributor.
     */
    public function feedRuns(): HasMany
    {
        return $this->hasMany(FeedRun::class);
    }

    /**
     * The most recent ingestion run for this distributor.
     */
    public function latestFeedRun(): HasOne
    {
        return $this->hasOne(FeedRun::class)->latestOfMany();
    }
}
