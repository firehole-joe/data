<?php

namespace Tests\Unit;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Services\Ammunition\AmmoAttributeExtractor;
use App\Services\Ammunition\MasterRoundCountReconciler;
use App\Services\Feeds\AmmoPricingGuardrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterRoundCountReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private MasterRoundCountReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reconciler = new MasterRoundCountReconciler(
            new AmmoAttributeExtractor,
            new AmmoPricingGuardrail,
        );
    }

    /* ----------------------------------------------------------------- */
    /*  isCasePack */
    /* ----------------------------------------------------------------- */

    public function test_it_identifies_case_packs(): void
    {
        $this->assertTrue($this->reconciler->isCasePack('MT9A-CSDP', 'MAGTECH 9MM 124GR FMJ 50/1000', 257.65, '9mm Luger'));
        $this->assertTrue($this->reconciler->isCasePack('X', 'FEDERAL 9MM 1000RD CASE', 240.00, '9mm Luger'));
        $this->assertTrue($this->reconciler->isCasePack('X', 'PMC 9MM 1000 ROUNDS', 5.00, '9mm Luger'));
        // Price alone gives a centerfire pistol / rifle box away.
        $this->assertTrue($this->reconciler->isCasePack('PLAIN', 'BLAZER 9MM 115GR FMJ', 199.00, '9mm Luger'));
    }

    public function test_slash_notation_box_is_not_a_case_pack(): void
    {
        // "50/1000" is a retail box drawn from a case, not a case.
        $this->assertFalse($this->reconciler->isCasePack('MG9A', 'MAGTECH 9MM 124GR FMJ 50/1000', 12.88, '9mm Luger'));
        $this->assertFalse($this->reconciler->isCasePack('AE9', 'FEDERAL AE 9MM 115GR FMJ 50RD', 11.99, '9mm Luger'));
        // A pricey shotshell box is not "centerfire pistol/rifle" so the
        // price rule does not apply.
        $this->assertFalse($this->reconciler->isCasePack('TSS12', 'FED TSS 12GA #9', 175.00, '12 Gauge'));
    }

    /* ----------------------------------------------------------------- */
    /*  reconcile */
    /* ----------------------------------------------------------------- */

    private function master(int $roundsPerBox, string $caliber = '9mm Luger'): MasterAmmunition
    {
        static $n = 0;
        $n++;

        return MasterAmmunition::create([
            'manufacturer' => 'Magtech',
            'mfr_part_number' => "MPN-{$n}",
            'name' => "Master {$n}",
            'caliber' => $caliber,
            'rounds_per_box' => $roundsPerBox,
            'is_tracked_in_report' => true,
        ]);
    }

    private function offering(MasterAmmunition $master, string $sku, string $desc, float $price): DistributorProduct
    {
        $distributor = Distributor::firstOrCreate(['slug' => 'rsr'], [
            'name' => 'RSR', 'driver_class' => 'X', 'transport_type' => 'sftp', 'connection_settings' => [],
        ]);

        return DistributorProduct::create([
            'distributor_id' => $distributor->id,
            'master_ammunition_id' => $master->id,
            'distributor_sku' => $sku,
            'raw_description' => $desc,
            'wholesale_price' => $price,
            'quantity_available' => 10,
            'is_in_stock' => true,
        ]);
    }

    public function test_it_pulls_a_case_pinned_master_down_to_the_box_consensus(): void
    {
        $master = $this->master(1000);
        $this->offering($master, 'MG9A', 'MAGTECH 9MM 124GR FMJ 50/1000', 12.88);
        $this->offering($master, 'AE9', 'FEDERAL AE 9MM 115GR FMJ 50RD', 11.99);
        $this->offering($master, 'MG9A-CSDP', 'MAGTECH 9MM 124GR FMJ 1000RD CASE', 257.65);

        $this->assertSame(50, $this->reconciler->reconcile($master));
        $this->assertSame(50, (int) $master->fresh()->rounds_per_box);
    }

    public function test_it_leaves_a_plausible_box_master_alone(): void
    {
        $master = $this->master(50);
        $this->offering($master, 'MG9A', 'MAGTECH 9MM 124GR FMJ 50RD', 12.88);

        $this->assertNull($this->reconciler->reconcile($master));
        $this->assertSame(50, (int) $master->fresh()->rounds_per_box);
    }

    public function test_it_will_not_correct_a_master_with_only_case_offerings(): void
    {
        $master = $this->master(1000);
        $this->offering($master, 'MG9A-CSDP', 'MAGTECH 9MM 124GR FMJ 1000RD CASE', 257.65);

        $this->assertNull($this->reconciler->reconcile($master), 'no box votes — leave it for a human');
        $this->assertSame(1000, (int) $master->fresh()->rounds_per_box);
    }

    public function test_it_ignores_non_centerfire_masters(): void
    {
        $master = $this->master(500, '.22 LR');
        $this->offering($master, 'CCI22', 'CCI MINI-MAG 22LR 40GR 100RD', 8.00);

        $this->assertNull($this->reconciler->reconcile($master), 'rimfire bulk counts are legitimate');
        $this->assertSame(500, (int) $master->fresh()->rounds_per_box);
    }

    public function test_preview_mode_does_not_write(): void
    {
        $master = $this->master(1000);
        $this->offering($master, 'MG9A', 'MAGTECH 9MM 124GR FMJ 50RD', 12.88);

        $this->assertSame(50, $this->reconciler->reconcile($master, persist: false));
        $this->assertSame(1000, (int) $master->fresh()->rounds_per_box, 'preview must not persist');
    }

    public function test_reconcile_is_null_safe(): void
    {
        $this->assertNull($this->reconciler->reconcile(null));
    }
}
