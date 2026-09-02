<?php

namespace Tests\Unit;

use App\Services\Feeds\AmmoPricingGuardrail;
use PHPUnit\Framework\TestCase;

class AmmoPricingGuardrailTest extends TestCase
{
    private AmmoPricingGuardrail $guardrail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardrail = new AmmoPricingGuardrail;
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function tierProvider(): array
    {
        return [
            '.22 LR is rimfire' => ['.22 LR', AmmoPricingGuardrail::TIER_RIMFIRE],
            '.17 HMR is rimfire' => ['.17 HMR', AmmoPricingGuardrail::TIER_RIMFIRE],
            '9mm is centerfire handgun' => ['9mm Luger', AmmoPricingGuardrail::TIER_HANDGUN],
            '.45 ACP is centerfire handgun' => ['.45 ACP', AmmoPricingGuardrail::TIER_HANDGUN],
            '10mm Auto is centerfire handgun' => ['10mm Auto', AmmoPricingGuardrail::TIER_HANDGUN],
            '5.56 is centerfire rifle' => ['5.56x45mm NATO', AmmoPricingGuardrail::TIER_RIFLE],
            '.308 is centerfire rifle' => ['.308 Winchester', AmmoPricingGuardrail::TIER_RIFLE],
            '8.6 Blackout is centerfire rifle' => ['8.6 Blackout', AmmoPricingGuardrail::TIER_RIFLE],
            '12 Gauge is shotshell' => ['12 Gauge', AmmoPricingGuardrail::TIER_SHOTSHELL],
            '20 Gauge is shotshell' => ['20 Gauge', AmmoPricingGuardrail::TIER_SHOTSHELL],
            'unknown caliber falls to default' => ['.44 Magnum', AmmoPricingGuardrail::TIER_DEFAULT],
            'null caliber falls to default' => [null, AmmoPricingGuardrail::TIER_DEFAULT],
        ];
    }

    /**
     * @dataProvider tierProvider
     */
    public function test_it_categorises_calibers_into_tiers(?string $caliber, string $expected): void
    {
        $this->assertSame($expected, $this->guardrail->tierFor($caliber));
    }

    /**
     * price, roundCount, caliber, expectedValid
     *
     * @return array<string, array{0: float, 1: int, 2: ?string, 3: bool}>
     */
    public static function boundaryProvider(): array
    {
        return [
            // The reported bug: $12.88 case-count of 1000 on a box = $0.013/rd.
            'reported bug — 9mm at $0.013' => [12.88, 1000, '9mm Luger', false],
            'reported fix — 9mm at $0.258' => [12.88, 50, '9mm Luger', true],
            'dedicated case — 9mm $257.65 / 1000' => [257.65, 1000, '9mm Luger', true],

            // Handgun tier band is [0.08, 3.50].
            'handgun exactly on the floor' => [4.00, 50, '9mm Luger', true],
            'handgun just below the floor' => [3.90, 50, '9mm Luger', false],
            'handgun exactly on the ceiling' => [175.00, 50, '.45 ACP', true],
            'handgun just above the ceiling' => [176.00, 50, '.45 ACP', false],

            // Rimfire tier band is [0.02, 0.60].
            'rimfire cheap bulk on the floor' => [10.00, 500, '.22 LR', true],
            'rimfire below the floor' => [8.00, 500, '.22 LR', false],
            'rimfire premium on the ceiling' => [30.00, 50, '.22 LR', true],
            'rimfire above the ceiling' => [40.00, 50, '.22 LR', false],

            // Rifle tier band is [0.20, 8.00].
            'rifle on the floor' => [4.00, 20, '5.56x45mm NATO', true],
            'rifle below the floor' => [3.00, 20, '5.56x45mm NATO', false],
            'rifle on the ceiling' => [160.00, 20, '6.5 Creedmoor', true],
            'rifle above the ceiling' => [170.00, 20, '6.5 Creedmoor', false],

            // Shotshell tier band is [0.15, 5.00].
            'shotshell on the floor' => [3.75, 25, '12 Gauge', true],
            'shotshell below the floor' => [3.00, 25, '12 Gauge', false],

            // Default tier band is [0.05, 10.00].
            'unknown caliber within default band' => [50.00, 20, '.44 Magnum', true],
            'unknown caliber below default floor' => [0.50, 20, '.44 Magnum', false],

            // Non-positive inputs.
            'zero round count is invalid' => [12.88, 0, '9mm Luger', false],
            'negative round count is invalid' => [12.88, -5, '9mm Luger', false],
            'no wholesale price is not a fault' => [0.0, 50, '9mm Luger', true],
        ];
    }

    /**
     * @dataProvider boundaryProvider
     */
    public function test_it_validates_against_caliber_tier_boundaries(float $price, int $count, ?string $caliber, bool $expectedValid): void
    {
        $result = $this->guardrail->validate($price, $count, $caliber);

        $this->assertSame($expectedValid, $result['is_valid'], $result['reason'] ?? 'expected a reason');
        $this->assertArrayHasKey('cost_per_round', $result);
        $this->assertArrayHasKey('reason', $result);

        if ($expectedValid) {
            $this->assertNull($result['reason']);
        } else {
            $this->assertIsString($result['reason']);
        }
    }

    public function test_it_reports_the_computed_cost_per_round(): void
    {
        $this->assertEqualsWithDelta(0.2576, $this->guardrail->validate(12.88, 50, '9mm Luger')['cost_per_round'], 1e-9);
        $this->assertEqualsWithDelta(0.0129, $this->guardrail->validate(12.88, 1000, '9mm Luger')['cost_per_round'], 1e-9);
        $this->assertSame(0.0, $this->guardrail->validate(12.88, 0, '9mm Luger')['cost_per_round']);
    }

    public function test_bounds_for_returns_the_tier_band(): void
    {
        $this->assertSame([0.08, 3.50], $this->guardrail->boundsFor('9mm Luger'));
        $this->assertSame([0.02, 0.60], $this->guardrail->boundsFor('.22 LR'));
        $this->assertSame([0.05, 10.00], $this->guardrail->boundsFor('mystery'));
    }
}
