<?php

/**
 * Tier price maths ("Nunua zaidi, lipa kidogo") — the only place a unit price is chosen.
 * No database here: it receives a product's tiers and a quantity, so it is simple to test.
 *
 * Tiers are a list like [['tier_min_quantity' => 1, 'tier_unit_price' => 5500], ['tier_min_quantity' => 6, …], …],
 * sorted by tier_min_quantity (as Product::getPriceTiers() returns them).
 *
 *   Pricing::tierForQuantity($tiers, 8)     → the "6+" tier (5,000 each)
 *   Pricing::nextTierHint($tiers, 8)        → ['extra_quantity' => 16, 'unit_price' => 4700]  ("Ongeza pcs 16 upate TZS 4,700")
 */
class Pricing
{
    /** The tier this quantity reaches: the one with the biggest tier_min_quantity that is ≤ the quantity. */
    public static function tierForQuantity(array $tiers, int $quantity): array
    {
        $reached_tier = $tiers[0];
        foreach ($tiers as $tier) {
            if ($tier['tier_min_quantity'] <= $quantity) {
                $reached_tier = $tier;
            }
        }
        return $reached_tier;
    }

    /** How many more pieces reach the next, cheaper tier — or null when the best tier is reached. */
    public static function nextTierHint(array $tiers, int $quantity): ?array
    {
        foreach ($tiers as $tier) {
            if ($tier['tier_min_quantity'] > $quantity) {
                return ['extra_quantity' => $tier['tier_min_quantity'] - $quantity, 'unit_price' => $tier['tier_unit_price']];
            }
        }
        return null;
    }

    /** One cart line priced: unit price, line total, and how much is saved compared with the normal price. */
    public static function priceLine(array $tiers, int $quantity): array
    {
        $tier         = self::tierForQuantity($tiers, $quantity);
        $normal_price = $tiers[0]['tier_unit_price'];

        return [
            'unit_price'        => $tier['tier_unit_price'],
            'tier_min_quantity' => $tier['tier_min_quantity'],
            'line_total'        => $tier['tier_unit_price'] * $quantity,
            'line_savings'      => ($normal_price - $tier['tier_unit_price']) * $quantity,   // "Unaokoa TZS …"
            'next_tier_hint'    => self::nextTierHint($tiers, $quantity),
        ];
    }
}
