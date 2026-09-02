<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillAmmoAttributesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function distributor(array $overrides = []): Distributor
    {
        static $n = 0;
        $n++;

        return Distributor::create(array_merge([
            'name' => "Distributor {$n}",
            'slug' => "dist-{$n}",
            'driver_class' => 'App\\Services\\Feeds\\Drivers\\RsrFeedDriver',
            'transport_type' => 'sftp',
            'connection_settings' => [],
            'is_active' => true,
        ], $overrides));
    }

    private function master(array $overrides = []): MasterAmmunition
    {
        static $n = 0;
        $n++;

        return MasterAmmunition::create(array_merge([
            'manufacturer' => 'Magtech',
            'mfr_part_number' => "MPN-{$n}",
            'name' => "Master {$n}",
            'caliber' => '9mm Luger',
            'bullet_weight_gr' => 115,
            'bullet_type' => 'FMJ',
            'rounds_per_box' => 50,
            'is_tracked_in_report' => true,
        ], $overrides));
    }

    private function offering(MasterAmmunition $master, Distributor $distributor, array $overrides = []): DistributorProduct
    {
        static $n = 0;
        $n++;

        return DistributorProduct::create(array_merge([
            'distributor_id' => $distributor->id,
            'master_ammunition_id' => $master->id,
            'distributor_sku' => "SKU-{$n}",
            'raw_description' => 'raw description',
            'wholesale_price' => 10.00,
            'quantity_available' => 100,
            'is_in_stock' => true,
        ], $overrides));
    }

    public function test_it_corrects_a_master_stuck_on_the_case_count(): void
    {
        $distributor = $this->distributor(['slug' => 'rsr']);
        // The master was created before slash notation was handled: it
        // carries the case count (1000) instead of the box count (50).
        $master = $this->master(['rounds_per_box' => 1000]);
        $offering = $this->offering($master, $distributor, [
            'distributor_sku' => 'MT9A',
            'raw_description' => 'MAGTECH 9MM LUGER 115GR FMJ 50/1000',
            'wholesale_price' => 12.88,
        ]);

        $this->artisan('ammo:backfill-attributes')->assertExitCode(0);

        $this->assertSame(50, $master->fresh()->rounds_per_box);
        $this->assertEqualsWithDelta(0.2576, (float) $offering->fresh()->cost_per_round, 0.0001);
    }

    public function test_a_case_sku_keeps_the_case_count(): void
    {
        $distributor = $this->distributor(['slug' => 'rsr']);
        $master = $this->master(['rounds_per_box' => 50]);
        $offering = $this->offering($master, $distributor, [
            'distributor_sku' => 'MT9A-CSDP',
            'raw_description' => 'MAGTECH 9MM LUGER 115GR FMJ 50/1000',
            'wholesale_price' => 257.65,
        ]);

        $this->artisan('ammo:backfill-attributes')->assertExitCode(0);

        $this->assertSame(1000, $master->fresh()->rounds_per_box);
        $this->assertEqualsWithDelta(0.2577, (float) $offering->fresh()->cost_per_round, 0.0002);
    }

    public function test_it_flags_a_centerfire_offering_that_is_still_implausibly_cheap(): void
    {
        $distributor = $this->distributor(['slug' => 'rsr']);
        // No packaging cue in the description, and the master round count
        // cannot be corrected automatically, so the CPR stays broken
        // (10.00 / 1000 = $0.01/round).
        $master = $this->master(['caliber' => '9mm Luger', 'rounds_per_box' => 1000]);
        $offering = $this->offering($master, $distributor, [
            'distributor_sku' => 'BULK9',
            'raw_description' => 'RECLAIMED 9MM RANGE BRASS LOADED',
            'wholesale_price' => 10.00,
        ]);

        $this->artisan('ammo:backfill-attributes')
            ->expectsOutputToContain('Flagged for review (low $/round)')
            ->expectsOutputToContain('priced below')
            ->assertExitCode(0);

        $this->assertSame(1000, $master->fresh()->rounds_per_box, 'round count left untouched — no reliable signal');
        $this->assertEqualsWithDelta(0.01, (float) $offering->fresh()->cost_per_round, 0.0001);
    }

    public function test_healthy_centerfire_and_cheap_rimfire_are_not_flagged(): void
    {
        $distributor = $this->distributor(['slug' => 'rsr']);

        $nine = $this->master(['caliber' => '9mm Luger', 'rounds_per_box' => 50]);
        $this->offering($nine, $distributor, ['raw_description' => '9MM 115GR FMJ 50RD', 'wholesale_price' => 12.88]);

        $rimfire = $this->master(['caliber' => '.22 LR', 'rounds_per_box' => 500, 'mfr_part_number' => 'RF1']);
        $this->offering($rimfire, $distributor, ['raw_description' => 'CCI 22LR 40GR 500RD', 'wholesale_price' => 15.00]);

        $this->artisan('ammo:backfill-attributes')
            ->expectsOutputToContain('Flagged for review (low $/round)')
            ->doesntExpectOutputToContain('offering priced below')
            ->assertExitCode(0);
    }

    public function test_distributor_option_scopes_reprocessing_to_one_feed(): void
    {
        $rsr = $this->distributor(['slug' => 'rsr']);
        $other = $this->distributor(['slug' => 'lipseys']);

        $rsrMaster = $this->master(['rounds_per_box' => 1000]);
        $rsrOffering = $this->offering($rsrMaster, $rsr, [
            'distributor_sku' => 'MT9A',
            'raw_description' => 'MAGTECH 9MM LUGER 115GR FMJ 50/1000',
            'wholesale_price' => 12.88,
        ]);

        $otherMaster = $this->master(['rounds_per_box' => 1000, 'mfr_part_number' => 'OTH1']);
        $otherOffering = $this->offering($otherMaster, $other, [
            'distributor_sku' => 'LIP9A',
            'raw_description' => 'MAGTECH 9MM LUGER 115GR FMJ 50/1000',
            'wholesale_price' => 12.88,
        ]);

        $this->artisan('ammo:backfill-attributes', ['--distributor' => 'rsr'])
            ->expectsOutputToContain('for [rsr]')
            ->assertExitCode(0);

        $this->assertSame(50, $rsrMaster->fresh()->rounds_per_box);
        $this->assertNotNull($rsrOffering->fresh()->cost_per_round);

        // The lipseys offering was outside the scope and left alone.
        $this->assertSame(1000, $otherMaster->fresh()->rounds_per_box);
        $this->assertNull($otherOffering->fresh()->cost_per_round);
    }
}
