<?php

/**
 * Numbers for the admin dashboard and its tabs (Overview, Orders, Sales, Customers, Stock).
 * Money numbers come from Report, so the dashboard and the Reports page always agree.
 */
class Dashboard
{
    private const LOW_STOCK_LIST_SIZE = 10;

    public function __construct(private Database $db) {}

    /**
     * Sales for today and the last 7 days (including today), in Tanzania time, without cancelled and expired orders:
     * ['today' => summary, 'last_7_days' => summary]; each summary has order_count, revenue, average_order, …
     * (Report::getSummaryForDays — the same numbers as the Reports page).
     */
    public function getSales(): array
    {
        $report = new Report($this->db);
        $today  = $this->today();

        return [
            'today'       => $report->getSummaryForDays(['date_from' => $today->format('Y-m-d'), 'date_to' => $today->format('Y-m-d')]),
            'last_7_days' => $report->getSummaryForDays(['date_from' => $today->modify('-6 days')->format('Y-m-d'), 'date_to' => $today->format('Y-m-d')]),
        ];
    }

    /**
     * Totals since CHIMBO started (the Overview's "All time" cards), in one query. Sales numbers leave out cancelled
     * and expired orders, like Reports: customers (active), businesses_verified, orders (every order ever placed),
     * orders_delivered, revenue, average_order, pieces_sold, products_in_shop, sellers.
     */
    public function getAllTimeTotals(): array
    {
        $totals = array_map('intval', $this->db->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM users WHERE user_status = 'active')                                  AS customers,
                (SELECT COUNT(*) FROM business_profiles WHERE business_verification_status = 'verified')  AS businesses_verified,
                (SELECT COUNT(*) FROM orders)                                                              AS orders,
                (SELECT COUNT(*) FROM orders WHERE order_status = 'delivered')                             AS orders_delivered,
                (SELECT COUNT(*) FROM orders WHERE order_status NOT IN ('cancelled', 'expired'))          AS counted_orders,
                (SELECT COALESCE(SUM(order_total), 0) FROM orders WHERE order_status NOT IN ('cancelled', 'expired')) AS revenue,
                (SELECT COALESCE(SUM(i.order_item_quantity), 0) FROM order_items i JOIN orders o ON o.order_id = i.order_id
                  WHERE o.order_status NOT IN ('cancelled', 'expired'))                                    AS pieces_sold,
                (SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND product_is_active = 1)         AS products_in_shop,
                (SELECT COUNT(*) FROM sellers WHERE seller_status = 'active')                              AS sellers"
        ));
        $totals['average_order'] = $totals['counted_orders'] > 0 ? intdiv($totals['revenue'], $totals['counted_orders']) : 0;
        unset($totals['counted_orders']);
        return $totals;
    }

    public const CHART_DAY_OPTIONS = [7, 14, 30, 90];

    /** Revenue and orders per day for the last $days days (including today, Tanzania time), every day listed. */
    public function getDailySales(int $days): array
    {
        return (new Report($this->db))->getDailySales($this->dayRange($days));
    }

    // ------------------------------------------------------------------ Sales tab

    /**
     * The Sales tab for the last $days days (Report's numbers, so they match the Reports page):
     * summary, by_category, by_payment_method, top_products (5), top_regions (5), and the date_from / date_to used.
     */
    public function getSalesBreakdown(int $days): array
    {
        $report = (new Report($this->db))->getSalesReport($this->dayRange($days));

        return [
            'date_from'         => $report['date_from'],
            'date_to'           => $report['date_to'],
            'summary'           => $report['summary'],
            'by_category'       => $report['by_category'],
            'by_payment_method' => $report['by_payment_method'],
            'top_products'      => array_slice($report['top_products'], 0, 5),
            'top_regions'       => array_slice($report['by_region'], 0, 5),
        ];
    }

    // ------------------------------------------------------------------ Customers tab

    /**
     * The Customers tab for the last $days days (deleted accounts left out): counts (customers, new_in_period, verified_businesses, suspended),
     * new_per_day (every day, zeros included), top_customers (5, by money spent on counted orders in the period)
     * and newest_customers (8).
     */
    public function getCustomerStats(int $days): array
    {
        $range    = $this->dayRange($days);
        $from_utc = localTimeToUtc($range['date_from'] . ' 00:00:00');
        $to_utc   = localTimeToUtc($range['date_to'] . ' 00:00:00 +1 day');

        $counts = array_map('intval', $this->db->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM users WHERE user_status = 'active')                                   AS customers,
                (SELECT COUNT(*) FROM users WHERE user_status <> 'deleted' AND created_at >= :from_utc AND created_at < :to_utc) AS new_in_period,
                (SELECT COUNT(*) FROM business_profiles WHERE business_verification_status = 'verified')   AS verified_businesses,
                (SELECT COUNT(*) FROM users WHERE user_status = 'suspended')                                AS suspended",
            ['from_utc' => $from_utc, 'to_utc' => $to_utc]
        ));

        $offset = (new DateTimeImmutable('now', $this->timezone()))->format('P');   // e.g. "+03:00"
        $joined = array_map('intval', array_column($this->db->fetchAll(
            "SELECT DATE(CONVERT_TZ(created_at, '+00:00', '{$offset}')) AS join_date, COUNT(*) AS customer_count
             FROM users WHERE user_status <> 'deleted' AND created_at >= :from_utc AND created_at < :to_utc
             GROUP BY join_date",
            ['from_utc' => $from_utc, 'to_utc' => $to_utc]
        ), 'customer_count', 'join_date'));

        $new_per_day = [];
        for ($day = new DateTimeImmutable($range['date_from']); $day <= new DateTimeImmutable($range['date_to']); $day = $day->modify('+1 day')) {
            $new_per_day[] = ['join_date' => $day->format('Y-m-d'), 'customer_count' => $joined[$day->format('Y-m-d')] ?? 0];
        }

        $top_customers = $this->db->fetchAll(
            "SELECT u.user_id, u.user_full_name, u.user_phone, b.business_name,
                    COUNT(*) AS order_count, SUM(o.order_total) AS total_spent
             FROM orders o
             JOIN users u ON u.user_id = o.user_id
             LEFT JOIN business_profiles b ON b.user_id = u.user_id
             WHERE o.order_status NOT IN ('cancelled', 'expired') AND o.order_placed_at >= :from_utc AND o.order_placed_at < :to_utc
             GROUP BY u.user_id, u.user_full_name, u.user_phone, b.business_name
             ORDER BY total_spent DESC, order_count DESC
             LIMIT 5",
            ['from_utc' => $from_utc, 'to_utc' => $to_utc]
        );

        $newest_customers = $this->db->fetchAll(
            "SELECT u.user_id, u.user_full_name, u.user_phone, u.created_at, b.business_name, r.region_name
             FROM users u
             LEFT JOIN business_profiles b ON b.user_id = u.user_id
             LEFT JOIN regions r ON r.region_id = b.region_id
             WHERE u.user_status <> 'deleted'
             ORDER BY u.user_id DESC
             LIMIT 8"
        );

        return [
            'date_from'        => $range['date_from'],
            'date_to'          => $range['date_to'],
            'counts'           => $counts,
            'new_per_day'      => $new_per_day,
            'top_customers'    => $top_customers,
            'newest_customers' => $newest_customers,
        ];
    }

    // ------------------------------------------------------------------ Stock tab

    private const STOCK_LIST_SIZE = 10;
    private const SLOW_MOVER_DAYS = 30;

    /**
     * The Stock tab: counts (in_shop, sold_out, low, hidden, running_offers), best_sellers (pieces sold in the last
     * 30 days, top 8), slow_movers (in stock but not sold in 30 days, most pieces first) and offers_ending
     * (running offers, ending soonest first).
     */
    public function getStockStats(): array
    {
        $threshold = ProductEditor::LOW_STOCK_THRESHOLD;
        $since_utc = gmdate('Y-m-d H:i:s', time() - self::SLOW_MOVER_DAYS * 86400);

        $counts = array_map('intval', $this->db->fetchOne(
            "SELECT
                SUM(product_is_active = 1)                                                    AS in_shop,
                SUM(product_is_active = 1 AND product_stock_quantity = 0)                     AS sold_out,
                SUM(product_is_active = 1 AND product_stock_quantity BETWEEN 1 AND {$threshold}) AS low,
                SUM(product_is_active = 0)                                                    AS hidden,
                (SELECT COUNT(*) FROM product_offers offer WHERE " . ProductOffer::RUNNING_SQL . ") AS running_offers
             FROM products WHERE deleted_at IS NULL"
        ));

        $best_sellers = $this->db->fetchAll(
            "SELECT i.product_id, MAX(i.order_item_product_name) AS product_name, SUM(i.order_item_quantity) AS pieces_sold
             FROM order_items i
             JOIN orders o ON o.order_id = i.order_id
             WHERE o.order_status NOT IN ('cancelled', 'expired') AND o.order_placed_at >= :since_utc
             GROUP BY i.product_id
             ORDER BY pieces_sold DESC
             LIMIT 8",
            ['since_utc' => $since_utc]
        );

        $slow_movers = $this->db->fetchAll(
            "SELECT p.product_id, p.product_name, p.product_sku, p.product_stock_quantity, p.product_unit_label,
                    (SELECT MAX(o.order_placed_at) FROM order_items i JOIN orders o ON o.order_id = i.order_id
                      WHERE i.product_id = p.product_id AND o.order_status NOT IN ('cancelled', 'expired')) AS last_sold_at
             FROM products p
             WHERE p.deleted_at IS NULL AND p.product_is_active = 1 AND p.product_stock_quantity > 0
               AND NOT EXISTS (SELECT 1 FROM order_items i JOIN orders o ON o.order_id = i.order_id
                               WHERE i.product_id = p.product_id AND o.order_status NOT IN ('cancelled', 'expired')
                                 AND o.order_placed_at >= :since_utc)
             ORDER BY p.product_stock_quantity DESC, p.product_name
             LIMIT " . self::STOCK_LIST_SIZE,
            ['since_utc' => $since_utc]
        );

        $offers_ending = $this->db->fetchAll(
            'SELECT offer.product_offer_id, offer.product_offer_percent, offer.product_offer_ends_at, p.product_id, p.product_name
             FROM product_offers offer JOIN products p ON p.product_id = offer.product_id
             WHERE ' . ProductOffer::RUNNING_SQL . '
             ORDER BY offer.product_offer_ends_at
             LIMIT ' . self::STOCK_LIST_SIZE
        );

        return [
            'counts'        => $counts,
            'best_sellers'  => array_map(fn (array $row): array => ['pieces_sold' => (int) $row['pieces_sold']] + $row, $best_sellers),
            'slow_movers'   => $slow_movers,
            'offers_ending' => $offers_ending,
        ];
    }

    /**
     * What needs someone right now, as numbers (the "needs attention" bar shows only those above 0):
     * payments_to_check, overdue_unpaid, refunds_due, orders_to_pack (confirmed, not packed yet),
     * businesses_to_verify (asked for verification), low_stock (sold out or low).
     */
    public function getAttentionCounts(): array
    {
        $threshold = ProductEditor::LOW_STOCK_THRESHOLD;
        $counts = $this->db->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM payments WHERE payment_status = 'submitted')                  AS payments_to_check,
                (SELECT COUNT(*) FROM orders WHERE order_status IN ('cancelled', 'expired')
                  AND order_payment_status = 'paid')                                                AS refunds_due,
                (SELECT COUNT(*) FROM orders WHERE order_status = 'confirmed')                      AS orders_to_pack,
                (SELECT COUNT(*) FROM orders o WHERE " . OrderManager::TRANSPORT_TO_CONFIRM_SQL . ")    AS transport_to_confirm,
                (SELECT COUNT(*) FROM business_profiles WHERE business_verification_status = 'pending') AS businesses_to_verify,
                (SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND product_is_active = 1
                  AND product_stock_quantity <= {$threshold})                                        AS low_stock"
        );
        return array_map('intval', $counts) + ['overdue_unpaid' => (new OrderManager($this->db))->countOverdueOrders()];
    }

    // The order pipeline, in the order an order moves through it
    public const PIPELINE_STATUSES = ['pending_payment', 'confirmed', 'packed', 'dispatched', 'in_transit'];
    private const WAITING_TO_PACK_LIST_SIZE = 10;

    /**
     * Orders tab numbers in one query: 'pipeline' = how many orders are in each PIPELINE_STATUSES status right now
     * (every status listed, 0 when empty), 'totals' = all / active / delivered / cancelled / expired.
     */
    public function getOrderPipeline(): array
    {
        $by_status = array_map('intval', array_column(
            $this->db->fetchAll('SELECT order_status, COUNT(*) AS order_count FROM orders GROUP BY order_status'),
            'order_count',
            'order_status'
        ));

        $pipeline = [];
        foreach (self::PIPELINE_STATUSES as $status) {
            $pipeline[$status] = $by_status[$status] ?? 0;
        }

        return [
            'pipeline' => $pipeline,
            'totals'   => [
                'all'       => array_sum($by_status),
                'active'    => array_sum($pipeline),
                'delivered' => $by_status['delivered'] ?? 0,
                'cancelled' => $by_status['cancelled'] ?? 0,
                'expired'   => $by_status['expired'] ?? 0,
            ],
        ];
    }

    /**
     * Confirmed orders not packed yet, the longest-waiting first (up to 10), with when they were confirmed
     * (the timeline row; cash orders are confirmed when placed, paid ones when the payment was confirmed).
     */
    public function getOrdersWaitingToPack(): array
    {
        return $this->db->fetchAll(
            "SELECT o.order_id, o.order_number, o.order_total, o.order_ship_region_name, o.order_payment_method,
                    u.user_full_name, u.user_phone,
                    (SELECT MAX(h.created_at) FROM order_status_history h
                      WHERE h.order_id = o.order_id AND h.order_status = 'confirmed') AS confirmed_at,
                    (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.order_id) AS item_count
             FROM orders o
             JOIN users u ON u.user_id = o.user_id
             WHERE o.order_status = 'confirmed'
             ORDER BY confirmed_at, o.order_id
             LIMIT " . self::WAITING_TO_PACK_LIST_SIZE
        );
    }

    /**
     * Orders out for delivery (dispatched or in transit) grouped by delivery agent, most orders first:
     * [{delivery_agent_id, delivery_agent_full_name, delivery_agent_phone, order_count, cash_to_collect, order_numbers}].
     * cash_to_collect = the totals of their cash-on-delivery orders.
     */
    public function getDeliveriesByAgent(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT a.delivery_agent_id, a.delivery_agent_full_name, a.delivery_agent_phone,
                    COUNT(*) AS order_count,
                    SUM(IF(o.order_payment_status = 'cod_pending', o.order_total, 0)) AS cash_to_collect,
                    GROUP_CONCAT(o.order_number ORDER BY o.order_id SEPARATOR ', ') AS order_numbers
             FROM orders o
             JOIN order_deliveries d ON d.order_id = o.order_id
             JOIN delivery_agents a  ON a.delivery_agent_id = d.delivery_agent_id
             WHERE o.order_status IN ('dispatched', 'in_transit')
             GROUP BY a.delivery_agent_id, a.delivery_agent_full_name, a.delivery_agent_phone
             ORDER BY order_count DESC, a.delivery_agent_full_name"
        );
        foreach ($rows as &$row) {
            $row['order_count']     = (int) $row['order_count'];
            $row['cash_to_collect'] = (int) $row['cash_to_collect'];
        }
        unset($row);
        return $rows;
    }

    /**
     * Products in the shop that are sold out or low (the Stock page's rule: ProductEditor::LOW_STOCK_THRESHOLD),
     * fewest pieces first: ['items' => up to 10 rows, 'low_count' => …, 'out_count' => …].
     */
    public function getLowStock(): array
    {
        $threshold = ProductEditor::LOW_STOCK_THRESHOLD;
        $in_shop   = 'deleted_at IS NULL AND product_is_active = 1';

        $counts = $this->db->fetchOne(
            "SELECT SUM(product_stock_quantity BETWEEN 1 AND {$threshold}) AS low_count,
                    SUM(product_stock_quantity = 0) AS out_count
             FROM products WHERE {$in_shop}"
        );
        $items = $this->db->fetchAll(
            "SELECT product_id, product_name, product_sku, product_stock_quantity, product_moq, product_unit_label
             FROM products
             WHERE {$in_shop} AND product_stock_quantity <= {$threshold}
             ORDER BY product_stock_quantity, product_name
             LIMIT " . self::LOW_STOCK_LIST_SIZE
        );

        return ['items' => $items, 'low_count' => (int) $counts['low_count'], 'out_count' => (int) $counts['out_count']];
    }

    /** The last $days days up to today (Tanzania), as ['date_from' => …, 'date_to' => …]. $days is one of CHART_DAY_OPTIONS. */
    private function dayRange(int $days): array
    {
        if (!in_array($days, self::CHART_DAY_OPTIONS, true)) {
            throw ApiException::validation(['days' => 'Choose 7, 14, 30 or 90 days.']);
        }
        $today = $this->today();
        return ['date_from' => $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), 'date_to' => $today->format('Y-m-d')];
    }

    /** Today in Tanzania (dashboard days follow Tanzania time, like Reports). */
    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', $this->timezone());
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) Env::get('APP_TIMEZONE', 'Africa/Dar_es_Salaam'));
    }
}
