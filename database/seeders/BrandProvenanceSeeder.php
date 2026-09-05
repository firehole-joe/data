<?php

namespace Database\Seeders;

use App\Models\BrandProvenance;
use Illuminate\Database\Seeder;

/**
 * Verified brand ownership / country-of-origin mappings for the public
 * supply-summary API's provenance features.
 *
 * Idempotent: run any time to refresh the classification. Where the feed
 * parser canonicalises a brand differently from its common label (e.g.
 * "Corbon" vs "Cor-Bon", "Armscor" vs "Rock Island") both spellings are
 * seeded so the join lands regardless of how the row was stored.
 */
class BrandProvenanceSeeder extends Seeder
{
    /**
     * @var array<string, array<string, string>> tier => [brand => notes]
     */
    private const MAP = [
        'american_owned_american_made' => [
            'Winchester' => 'Olin Corporation — US, publicly traded (NYSE: OLN)',
            'Hornady' => 'Hornady Mfg — US, family-owned (Grand Island, NE)',
            'White River Energetics' => 'US-manufactured',
            'Black Hills Ammunition' => 'US, family-owned (Rapid City, SD)',
            'Black Hills' => 'US, family-owned (Rapid City, SD)',
            'Cor-Bon' => 'US-manufactured (Sturgis, SD)',
            'Corbon' => 'US-manufactured (Sturgis, SD)',
            'DoubleTap' => 'US-manufactured (Cedar City, UT)',
            'SinterFire' => 'US-manufactured frangible (Kersey, PA)',
            'Nosler' => 'US, family-owned (Bend, OR)',
            'Sierra' => 'Clarus Corporation — US, publicly traded; manufactured Sedalia, MO',
            'Starline' => 'US, family-owned brass (Sedalia, MO)',
        ],
        'foreign_owned_us_manufactured' => [
            'Federal' => 'The Kinetic Group / Czechoslovak Group (CSG); manufactured Anoka, MN',
            'Remington' => 'The Kinetic Group / CSG; manufactured Lonoke, AR',
            'CCI' => 'The Kinetic Group / CSG; manufactured Lewiston, ID',
            'Speer' => 'The Kinetic Group / CSG; manufactured Lewiston, ID',
            'Hevi-Shot' => 'The Kinetic Group / CSG',
            'Alliant Powder' => 'The Kinetic Group / CSG',
            'Fiocchi' => 'Fiocchi (Italian parent, CSG-affiliated); US-manufactured lines (Ozark, MO / Little Rock, AR)',
        ],
        'imported_or_repackaged' => [
            'Magtech' => 'CBC Global Ammunition — Brazil',
            'PPU' => 'Prvi Partizan — Serbia',
            'Prvi Partizan' => 'Serbia',
            'PMC' => 'Poongsan — South Korea',
            'Aguila' => 'Industrias Tecnos — Mexico',
            'Wolf' => 'Imported; steel-case',
            'Tula' => 'Tula Cartridge Works — Russia; steel-case',
            'Barnaul' => 'Barnaul Cartridge Plant — Russia; steel-case',
            'Sterling' => 'Sterling — Turkey',
            'Norma' => 'Beretta Holding (Italy); manufactured Sweden',
            'Patriot Sports' => 'SVT Technology — Czech Republic',
            'Sellier & Bellot' => 'CBC Global Ammunition — Czech Republic',
            'Armscor' => 'Armscor / Rock Island — Philippines',
            'Rock Island' => 'Armscor — Philippines',
        ],
    ];

    public function run(): void
    {
        foreach (self::MAP as $provenance => $brands) {
            foreach ($brands as $brand => $notes) {
                BrandProvenance::updateOrCreate(
                    ['brand_name' => $brand],
                    ['provenance' => $provenance, 'notes' => $notes],
                );
            }
        }
    }
}
