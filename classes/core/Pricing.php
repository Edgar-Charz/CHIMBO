<?php

/**
 * Tier price maths ("Nunua zaidi, lipa kidogo") — the only place a unit price is chosen.
 * No database here: it receives a product's tiers and a quantity, so it is simple to test.
 *
 * Tiers are a list like [['tier_min_quantity' => 1, 'tier_unit_price' => 5500], ['tier_min_quantity' => 6, …], …],
 * sorted by tier_min_quantity (as Product::getPriceTiers() returns them).
 * A running offer ("Ofa", ProductOffer) takes its percentage off EVERY tier: withOffer() gives those tiers.
 *
 *   Pricing::tierForQuantity($tiers, 8)     → the "6+" tier (5,000 each)
 *   Pricing::nextTierHint($tiers, 8)        → ['extra_quantity' => 16, 'unit_price' => 4700]  ("Ongeza pcs 16 upate TZS 4,700")
 *   Pricing::priceLine($tiers, 8, 15)       → 4,250 each with a 15% offer
 */
class Pricing
{
    /** A price with the offer taken off, rounded to whole shillings: offerPrice(5000, 15) → 4250. */
    public static function offerPrice(int $price, int $offer_percent): int
    {
        return (int) round($price * (100 - $offer_percent) / 100);
    }

    /**
     * The tiers with the offer taken off. Each tier keeps its normal price as "tier_price_before_offer"
     * when an offer applies (for the crossed-out price). No offer (0) → the tiers unchanged.
     */
    public static function withOffer(array $tiers, int $offer_percent): array
    {
        if ($offer_percent <= 0) {
            return $tiers;
        }
        return array_map(fn (array $tier) => [
            'tier_min_quantity'       => $tier['tier_min_quantity'],
            'tier_unit_price'         => self::offerPrice($tier['tier_unit_price'], $offer_percent),
            'tier_price_before_offer' => $tier['tier_unit_price'],
        ], $tiers);
    }

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

    /**
     * One cart line priced: unit price, line total, and how much is saved compared with the normal price
     * (the first tier without any offer). $offer_percent = the product's running offer (0 = none).
     */
    public static function priceLine(array $tiers, int $quantity, int $offer_percent = 0): array
    {
        $normal_price = $tiers[0]['tier_unit_price'];
        $offer_tiers  = self::withOffer($tiers, $offer_percent);
        $tier         = self::tierForQuantity($offer_tiers, $quantity);

        return [
            'unit_price'        => $tier['tier_unit_price'],
            'tier_min_quantity' => $tier['tier_min_quantity'],
            'offer_percent'     => max(0, $offer_percent),
            'line_total'        => $tier['tier_unit_price'] * $quantity,
            'line_savings'      => ($normal_price - $tier['tier_unit_price']) * $quantity,   // "Unaokoa TZS …" (tiers + offer)
            'next_tier_hint'    => self::nextTierHint($offer_tiers, $quantity),
        ];
    }
}
