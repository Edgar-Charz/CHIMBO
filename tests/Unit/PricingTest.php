<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tier price maths, using Vaseline's tiers from the design: 1–5 5,500 · 6–23 5,000 · 24–59 4,700 · 60+ 4,400. */
final class PricingTest extends TestCase
{
    private const VASELINE_TIERS = [
        ['tier_min_quantity' => 1, 'tier_unit_price' => 5500],
        ['tier_min_quantity' => 6, 'tier_unit_price' => 5000],
        ['tier_min_quantity' => 24, 'tier_unit_price' => 4700],
        ['tier_min_quantity' => 60, 'tier_unit_price' => 4400],
    ];

    public static function tierBoundaries(): array
    {
        return [
            '1 piece'        => [1, 5500],
            '5 (last of 1–5)' => [5, 5500],
            '6 (first of 6+)' => [6, 5000],
            '23'             => [23, 5000],
            '24'             => [24, 4700],
            '59'             => [59, 4700],
            '60'             => [60, 4400],
            '500'            => [500, 4400],
        ];
    }

    #[DataProvider('tierBoundaries')]
    public function testUnitPriceAtEveryBoundary(int $quantity, int $expected_unit_price): void
    {
        $this->assertSame($expected_unit_price, Pricing::tierForQuantity(self::VASELINE_TIERS, $quantity)['tier_unit_price']);
    }

    public function testExampleFromTheDesign(): void
    {
        // 8 pieces: "6+ pcs @ TZS 5,000" → TZS 40,000, "Unaokoa TZS 4,000"
        $line = Pricing::priceLine(self::VASELINE_TIERS, 8);

        $this->assertSame(5000, $line['unit_price']);
        $this->assertSame(6, $line['tier_min_quantity']);
        $this->assertSame(40000, $line['line_total']);
        $this->assertSame(4000, $line['line_savings']);
        $this->assertSame(['extra_quantity' => 16, 'unit_price' => 4700], $line['next_tier_hint']);
    }

    public function testNoHintAtTheBestTier(): void
    {
        $this->assertNull(Pricing::nextTierHint(self::VASELINE_TIERS, 60));
    }

    public function testProductWithOneTierAlwaysUsesIt(): void
    {
        $one_tier = [['tier_min_quantity' => 12, 'tier_unit_price' => 1200]];

        $line = Pricing::priceLine($one_tier, 30);

        $this->assertSame(36000, $line['line_total']);
        $this->assertSame(0, $line['line_savings']);
        $this->assertNull($line['next_tier_hint']);
    }
}
