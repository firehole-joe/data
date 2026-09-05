<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Ownership / country-of-origin classification for one ammunition brand.
 *
 * Joined to {@see MasterAmmunition} by brand name rather than a foreign
 * key so the mapping stays authoritative in a single table.
 */
class BrandProvenance extends Model
{
    use HasFactory;

    /**
     * The three provenance tiers, most domestic first.
     *
     * @var array<int, string>
     */
    public const TIERS = [
        'american_owned_american_made',
        'foreign_owned_us_manufactured',
        'imported_or_repackaged',
    ];

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'brand_name',
        'provenance',
        'notes',
    ];
}
