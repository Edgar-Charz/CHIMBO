<?php

/**
 * Numbers for the admin dashboard.
 * All counts come from ONE query and are returned together (instead of one method per count).
 */
class Dashboard
{
    public function __construct(private Database $db) {}

    /** Customer and order numbers for the admin dashboard. */
    public function getSummaryCounts(): array
    {
        return $this->db->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM users WHERE user_status = 'active')                  AS total_customers,
                (SELECT COUNT(*) FROM users WHERE created_at >= UTC_DATE())               AS new_customers_today,
                (SELECT COUNT(*) FROM business_profiles
                  WHERE business_verification_status = 'verified')                        AS verified_businesses,
                (SELECT COUNT(*) FROM business_profiles
                  WHERE business_verification_status = 'pending')                         AS businesses_awaiting_verification,
                (SELECT COUNT(*) FROM orders)                                              AS total_orders,
                (SELECT COUNT(*) FROM orders WHERE order_status IN ('pending_payment', 'confirmed', 'packed', 'dispatched', 'in_transit')) AS active_orders,
                (SELECT COUNT(*) FROM orders WHERE order_status = 'pending_payment')       AS orders_awaiting_payment,
                (SELECT COUNT(*) FROM orders WHERE order_status = 'delivered')             AS delivered_orders,
                (SELECT COUNT(*) FROM payments WHERE payment_status = 'submitted')         AS payments_awaiting_review,
                (SELECT COUNT(*) FROM orders WHERE order_status IN ('cancelled', 'expired')
                  AND order_payment_status = 'paid')                                       AS refunds_due"
        );
    }
}
