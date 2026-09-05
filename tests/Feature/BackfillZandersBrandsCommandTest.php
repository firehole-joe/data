<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Services\Feeds\Drivers\ZandersFeedDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillZandersBrandsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function zanders(): Distributor
    {
        return Distributor::create([
            'name' => 'Zanders Sporting Goods',
            'slug' => 'zanders',
            'driver_class' => ZandersFeedDriver::class,
            'transport_type' => 'ftp',
            'connection_settings' => [],
        ]);
    }

    private function master(array $overrides = []): MasterAmmunition
    {
        static $n = 0;
        $n++;

        return MasterAmmunition::create(array_merge([
            'manufacturer' => 'Unknown',
            'mfr_part_number' => "MPN-{$n}",
            'name' => "Master {$n}",
            'caliber' => '9mm Luger',
            'bullet_weight_gr' => 115,
            'bullet_type' => 'FMJ',
            'rounds_per_box' => 50,
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

    public function test_it_backfills_the_brand_from_the_feed_manufacturer_column(): void
    {
        $zanders = $this->zanders();
        $master = $this->master(['mfr_part_number' => 'PP9MM']);
        $this->offering($master, $zanders, [
            'raw_manufacturer' => 'PPU',
            'raw_description' => 'Tac-Load 9mm Luger 124gr FMJ 50rd',
        ]);

        $this->artisan('ammo:backfill-zanders-brands')->assertExitCode(0);

        $this->assertSame('Prvi Partizan', $master->fresh()->manufacturer);
    }

    public function test_it_backfills_the_brand_from_the_description_prefix(): void
    {
        $zanders = $this->zanders();
        $master = $this->master();
        $this->offering($master, $zanders, [
            'raw_manufacturer' => null,
            'raw_description' => 'WINCHESTER USA 9MM 115GR FMJ 50RD',
        ]);

        $this->artisan('ammo:backfill-zanders-brands')->assertExitCode(0);

        $this->assertSame('Winchester', $master->fresh()->manufacturer);
    }

    public function test_dry_run_reports_but_persists_nothing(): void
    {
        $zanders = $this->zanders();
        $master = $this->master();
        $this->offering($master, $zanders, ['raw_manufacturer' => 'Federal']);

        $this->artisan('ammo:backfill-zanders-brands', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame('Unknown', $master->fresh()->manufacturer);
    }

    public function test_it_leaves_a_master_untouched_when_no_brand_can_be_derived(): void
    {
        $zanders = $this->zanders();
        $master = $this->master();
        $this->offering($master, $zanders, [
            'raw_manufacturer' => 'Mixed',
            'raw_description' => 'Reclaimed 9mm range brass, mixed headstamp',
        ]);

        $this->artisan('ammo:backfill-zanders-brands')->assertExitCode(0);

        $this->assertSame('Unknown', $master->fresh()->manufacturer);
    }

    public function test_it_ignores_masters_that_already_have_a_real_brand(): void
    {
        $zanders = $this->zanders();
        $master = $this->master(['manufacturer' => 'CCI']);
        $this->offering($master, $zanders, ['raw_manufacturer' => 'Federal']);

        $this->artisan('ammo:backfill-zanders-brands')->assertExitCode(0);

        $this->assertSame('CCI', $master->fresh()->manufacturer, 'a populated brand is never overwritten');
    }

    public function test_it_only_touches_masters_with_offerings_from_the_target_distributor(): void
    {
        $zanders = $this->zanders();
        $rsr = Distributor::create([
            'name' => 'RSR Group',
            'slug' => 'rsr',
            'driver_class' => 'App\\Services\\Feeds\\Drivers\\RsrFeedDriver',
            'transport_type' => 'sftp',
            'connection_settings' => [],
        ]);

        $rsrOnly = $this->master();
        $this->offering($rsrOnly, $rsr, ['raw_description' => 'FEDERAL 9MM 115GR FMJ']);

        $this->artisan('ammo:backfill-zanders-brands')->assertExitCode(0);

        $this->assertSame('Unknown', $rsrOnly->fresh()->manufacturer);
    }

    public function test_it_merges_into_an_existing_master_when_renaming_would_collide(): void
    {
        $zanders = $this->zanders();

        $canonical = MasterAmmunition::create([
            'manufacturer' => 'Prvi Partizan',
            'mfr_part_number' => 'PP9MM',
            'name' => 'Prvi Partizan 9mm 124gr FMJ',
            'caliber' => '9mm Luger',
            'bullet_weight_gr' => 124,
            'bullet_type' => 'FMJ',
            'rounds_per_box' => 50,
        ]);

        $unknown = $this->master(['mfr_part_number' => 'PP9MM']);
        $offering = $this->offering($unknown, $zanders, [
            'raw_manufacturer' => 'PPU',
            'raw_description' => 'Tac-Load 9mm Luger 124gr FMJ 50rd',
        ]);

        $this->artisan('ammo:backfill-zanders-brands')->assertExitCode(0);

        $this->assertNull(MasterAmmunition::find($unknown->id), 'the emptied Unknown master is deleted');
        $this->assertSame($canonical->id, $offering->fresh()->master_ammunition_id, 'its offering is re-pointed');
    }
}
