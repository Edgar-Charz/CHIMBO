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
     * ['today' => summary, 'last_7_days' => summary]; each summary has order_count, revenue, average_order, â€¦
     * (Report::getSummaryForDays â€” the same numbers as the Reports page).
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

    /** Period-aware activity cards for Overview; product availability remains a current inventory snapshot. */
    public function getOverviewPeriodTotals(array $range): array
    {
        $params = $this->rangeParams($range);
        $orders = $this->db->fetchOne(
            "SELECT COUNT(*) AS orders,
                    SUM(o.order_status = 'delivered') AS delivered_orders,
                    SUM(o.order_status IN ('pending_payment', 'confirmed', 'packed', 'dispatched', 'in_transit')) AS active_orders,
                    SUM(o.order_status NOT IN ('cancelled', 'expired')) AS counted_orders,
                    COALESCE(SUM(IF(o.order_status NOT IN ('cancelled', 'expired'), o.order_total, 0)), 0) AS revenue
             FROM orders o WHERE o.order_placed_at >= :from_utc AND o.order_placed_at < :to_utc",
            $params
        );
        $pieces = $this->db->fetchOne(
            "SELECT COALESCE(SUM(i.order_item_quantity), 0) AS pieces_sold
             FROM order_items i JOIN orders o ON o.order_id = i.order_id
             WHERE o.order_status NOT IN ('cancelled', 'expired')
               AND o.order_placed_at >= :pieces_from_utc AND o.order_placed_at < :pieces_to_utc",
            ['pieces_from_utc' => $params['from_utc'], 'pieces_to_utc' => $params['to_utc']]
        );
        $customers = $this->db->fetchOne(
            "SELECT COUNT(*) AS customers FROM users
             WHERE user_status <> 'deleted' AND created_at >= :from_utc AND created_at < :to_utc",
            $params
        );
        $totals = array_map('intval', $orders);
        $totals['pieces_sold'] = (int) $pieces['pieces_sold'];
        $totals['customers'] = (int) $customers['customers'];
        $totals['average_order'] = $totals['counted_orders'] > 0
            ? intdiv($totals['revenue'], $totals['counted_orders'])
            : 0;
        $totals['products_in_shop'] = (int) $this->db->fetchOne(
            'SELECT COUNT(*) AS product_count FROM products WHERE deleted_at IS NULL AND product_is_active = 1'
        )['product_count'];
        return $totals;
    }

    /** Resolve the shared dashboard period; long date ranges use monthly chart buckets. */
    public function getDateRange(array $filters): array
    {
        $data = Validator::validate($filters, [
            'period' => 'nullable|in:all_time,today,custom',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);
        $period = $data['period'] ?? 'all_time';
        $today = $this->today();

        if ($period === 'all_time') {
            $offset = $today->format('P');
            $first_date = $this->db->fetchOne(
                "SELECT MIN(first_date) AS first_date FROM (
                    SELECT DATE(CONVERT_TZ(MIN(order_placed_at), '+00:00', '{$offset}')) AS first_date FROM orders
                    UNION ALL SELECT DATE(CONVERT_TZ(MIN(created_at), '+00:00', '{$offset}')) AS first_date FROM users
                ) AS first_dates"
            )['first_date'] ?? null;
            $date_from = $first_date ?: $today->format('Y-m-d');
            return ['period' => $period, 'bucket' => 'month', 'date_from' => $date_from, 'date_to' => $today->format('Y-m-d'), 'label' => 'all time'];
        }

        if ($period === 'today') {
            $date = $today->format('Y-m-d');
            return ['period' => $period, 'bucket' => 'day', 'date_from' => $date, 'date_to' => $date, 'label' => 'today'];
        }

        if (!isset($data['date_from'], $data['date_to'])) {
            throw ApiException::validation(['date_from' => 'Choose both dates for a custom range.']);
        }
        $date_from = new DateTimeImmutable($data['date_from'], $this->timezone());
        $date_to = new DateTimeImmutable($data['date_to'], $this->timezone());
        if ($date_from > $date_to) {
            throw ApiException::validation(['date_from' => 'The start date must be on or before the end date.']);
        }
        $bucket = $date_from->diff($date_to)->days >= 366 ? 'month' : 'day';
        return [
            'period' => $period,
            'bucket' => $bucket,
            'date_from' => $date_from->format('Y-m-d'),
            'date_to' => $date_to->format('Y-m-d'),
            'label' => $date_from->format('j M Y') . ' - ' . $date_to->format('j M Y'),
        ];
    }

    /** Revenue and order counts grouped by day, or by month for all-time charts. */
    public function getDailySales(array $range): array
    {
        $params = $this->rangeParams($range);
        $offset = (new DateTimeImmutable('now', $this->timezone()))->format('P');
        $group = $range['bucket'] === 'month'
            ? "DATE_FORMAT(CONVERT_TZ(o.order_placed_at, '+00:00', '{$offset}'), '%Y-%m-01')"
            : "DATE(CONVERT_TZ(o.order_placed_at, '+00:00', '{$offset}'))";
        $rows = $this->db->fetchAll(
            "SELECT {$group} AS sales_date, COUNT(*) AS order_count, SUM(o.order_total) AS revenue
             FROM orders o WHERE o.order_status NOT IN ('cancelled', 'expired')
               AND o.order_placed_at >= :from_utc AND o.order_placed_at < :to_utc
             GROUP BY sales_date ORDER BY sales_date",
            $params
        );
        foreach ($rows as &$row) {
            $row['order_count'] = (int) $row['order_count'];
            $row['revenue'] = (int) $row['revenue'];
        }
        unset($row);

        if ($range['bucket'] === 'month') {
            $by_month = array_column($rows, null, 'sales_date');
            $months = [];
            $month = (new DateTimeImmutable($range['date_from']))->modify('first day of this month');
            $last_month = (new DateTimeImmutable($range['date_to']))->modify('first day of this month');
            while ($month <= $last_month) {
                $date = $month->format('Y-m-d');
                $months[] = $by_month[$date] ?? ['sales_date' => $date, 'order_count' => 0, 'revenue' => 0];
                $month = $month->modify('+1 month');
            }
            return $months;
        }
        $by_date = array_column($rows, null, 'sales_date');
        $days = [];
        for ($day = new DateTimeImmutable($range['date_from']); $day <= new DateTimeImmutable($range['date_to']); $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $days[] = $by_date[$date] ?? ['sales_date' => $date, 'order_count' => 0, 'revenue' => 0];
        }
        return $days;
    }

    // ------------------------------------------------------------------ Sales tab

    /**
     * The Sales tab for a selected local date range:
     * summary, by_category, by_payment_method, top_products (5), top_regions (5), and the date_from / date_to used.
     */
    public function getSalesBreakdown(array $range): array
    {
        $params = $this->rangeParams($range);
        $where = 'o.order_placed_at >= :from_utc AND o.order_placed_at < :to_utc';
        $counted = "o.order_status NOT IN ('cancelled', 'expired')";
        $summary_row = $this->db->fetchOne(
            "SELECT COUNT(*) AS order_count, COALESCE(SUM(o.order_total), 0) AS revenue,
                    COALESCE(SUM(o.order_delivery_fee), 0) AS delivery_fees,
                    (SELECT COALESCE(SUM(i.order_item_quantity), 0) FROM order_items i JOIN orders oi ON oi.order_id = i.order_id
                     WHERE oi.order_status NOT IN ('cancelled', 'expired') AND oi.order_placed_at >= :from_items AND oi.order_placed_at < :to_items) AS pieces_sold,
                    (SELECT COUNT(*) FROM orders oc WHERE oc.order_status IN ('cancelled', 'expired')
                     AND oc.order_placed_at >= :from_cancelled AND oc.order_placed_at < :to_cancelled) AS cancelled_count
             FROM orders o WHERE {$counted} AND {$where}",
            $params + ['from_items' => $params['from_utc'], 'to_items' => $params['to_utc'], 'from_cancelled' => $params['from_utc'], 'to_cancelled' => $params['to_utc']]
        );
        $summary = array_map('intval', $summary_row);
        $summary['average_order'] = $summary['order_count'] > 0 ? intdiv($summary['revenue'], $summary['order_count']) : 0;
        $categories = $this->db->fetchAll(
            "SELECT COALESCE(parent.category_name, c.category_name) AS category_name,
                    SUM(i.order_item_quantity) AS quantity, SUM(i.order_item_line_total) AS revenue
             FROM order_items i JOIN orders o ON o.order_id = i.order_id JOIN products p ON p.product_id = i.product_id
             JOIN categories c ON c.category_id = p.category_id LEFT JOIN categories parent ON parent.category_id = c.parent_category_id
             WHERE {$counted} AND {$where} GROUP BY category_name ORDER BY revenue DESC", $params
        );
        $top_products = $this->db->fetchAll(
            "SELECT i.product_id, MAX(i.order_item_product_name) AS product_name, MAX(i.order_item_sku) AS product_sku,
                    SUM(i.order_item_quantity) AS quantity, SUM(i.order_item_line_total) AS revenue
             FROM order_items i JOIN orders o ON o.order_id = i.order_id WHERE {$counted} AND {$where}
             GROUP BY i.product_id ORDER BY revenue DESC LIMIT 5", $params
        );
        $payment_methods = $this->db->fetchAll(
            "SELECT o.order_payment_method, COUNT(*) AS order_count, SUM(o.order_total) AS revenue,
                    SUM(IF(o.order_payment_status = 'paid', o.order_total, 0)) AS paid_total
             FROM orders o WHERE {$counted} AND {$where} GROUP BY o.order_payment_method ORDER BY revenue DESC", $params
        );
        $regions = $this->db->fetchAll(
            "SELECT o.order_ship_region_name AS region_name, COUNT(*) AS order_count, SUM(o.order_total) AS revenue
             FROM orders o WHERE {$counted} AND {$where} GROUP BY o.order_ship_region_name ORDER BY revenue DESC LIMIT 5", $params
        );
        foreach ([$categories, $top_products, $payment_methods, $regions] as &$rows) {
            foreach ($rows as &$row) {
                foreach (['quantity', 'revenue', 'order_count', 'paid_total', 'product_id'] as $column) {
                    if (isset($row[$column])) $row[$column] = (int) $row[$column];
                }
            }
            unset($row);
        }
        unset($rows);

        return [
            'date_from' => $range['date_from'], 'date_to' => $range['date_to'], 'summary' => $summary,
            'by_category' => $categories, 'by_payment_method' => $payment_methods,
            'top_products' => $top_products, 'top_regions' => $regions,
        ];
    }

    // ------------------------------------------------------------------ Customers tab

    /**
     * The Customers tab for a selected local date range (deleted accounts left out): counts (customers, new_in_period, verified_businesses, suspended),
     * new_per_day (every day, zeros included), top_customers (5, by money spent on counted orders in the period)
     * and newest_customers (8).
     */
    public function getCustomerStats(array $range): array
    {
        $params = $this->rangeParams($range);
        $counts = array_map('intval', $this->db->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM users WHERE user_status = 'active')                                   AS customers,
                (SELECT COUNT(*) FROM users WHERE user_status <> 'deleted' AND created_at >= :from_utc AND created_at < :to_utc) AS new_in_period,
                (SELECT COUNT(*) FROM business_profiles WHERE business_verification_status = 'verified')   AS verified_businesses,
                (SELECT COUNT(*) FROM users WHERE user_status = 'suspended')                                AS suspended",
             $params
        ));

        $offset = (new DateTimeImmutable('now', $this->timezone()))->format('P');
        $date_group = $range['bucket'] === 'month'
            ? "DATE_FORMAT(CONVERT_TZ(created_at, '+00:00', '{$offset}'), '%Y-%m-01')"
            : "DATE(CONVERT_TZ(created_at, '+00:00', '{$offset}'))";
        $joined = array_map('intval', array_column($this->db->fetchAll(
            "SELECT {$date_group} AS join_date, COUNT(*) AS customer_count
             FROM users WHERE user_status <> 'deleted' AND created_at >= :from_utc AND created_at < :to_utc
             GROUP BY join_date",
            $params
        ), 'customer_count', 'join_date'));

        $new_per_day = [];
        if ($range['bucket'] === 'month') {
            $month = (new DateTimeImmutable($range['date_from']))->modify('first day of this month');
            $last_month = (new DateTimeImmutable($range['date_to']))->modify('first day of this month');
            while ($month <= $last_month) {
                $date = $month->format('Y-m-d');
                $new_per_day[] = ['join_date' => $date, 'customer_count' => $joined[$date] ?? 0];
                $month = $month->modify('+1 month');
            }
        } else {
            for ($day = new DateTimeImmutable($range['date_from']); $day <= new DateTimeImmutable($range['date_to']); $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                $new_per_day[] = ['join_date' => $date, 'customer_count' => $joined[$date] ?? 0];
            }
        }

        if ($range['period'] === 'all_time') {
            $buyer_mix = $this->db->fetchOne(
                "SELECT SUM(order_count = 1) AS first_time_buyers,
                        SUM(order_count > 1) AS returning_buyers,
                        COUNT(*) AS total_buyers
                 FROM (
                     SELECT user_id, COUNT(*) AS order_count
                     FROM orders
                     WHERE order_status NOT IN ('cancelled', 'expired') AND order_placed_at < :to_utc
                     GROUP BY user_id
                 ) AS buyer_orders",
                ['to_utc' => $params['to_utc']]
            );
        } else {
            $buyer_mix = $this->db->fetchOne(
                "SELECT SUM(buyer.first_order_at >= :first_time_from_utc) AS first_time_buyers,
                        SUM(buyer.first_order_at < :returning_before_utc) AS returning_buyers,
                        COUNT(*) AS total_buyers
                 FROM (
                     SELECT period_orders.user_id, MIN(all_orders.order_placed_at) AS first_order_at
                     FROM orders period_orders
                     JOIN orders all_orders ON all_orders.user_id = period_orders.user_id
                         AND all_orders.order_status NOT IN ('cancelled', 'expired')
                         AND all_orders.order_placed_at < :all_orders_before_utc
                     WHERE period_orders.order_status NOT IN ('cancelled', 'expired')
                       AND period_orders.order_placed_at >= :period_from_utc
                       AND period_orders.order_placed_at < :period_to_utc
                     GROUP BY period_orders.user_id
                 ) AS buyer",
                [
                    'first_time_from_utc' => $params['from_utc'],
                    'returning_before_utc' => $params['from_utc'],
                    'all_orders_before_utc' => $params['to_utc'],
                    'period_from_utc' => $params['from_utc'],
                    'period_to_utc' => $params['to_utc'],
                ]
            );
        }
        $buyer_mix = array_map('intval', $buyer_mix);

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
            $params
        );

        $newest_customers = $this->db->fetchAll(
            "SELECT u.user_id, u.user_full_name, u.user_phone, u.created_at, b.business_name, r.region_name
             FROM users u
             LEFT JOIN business_profiles b ON b.user_id = u.user_id
             LEFT JOIN regions r ON r.region_id = b.region_id
             WHERE u.user_status <> 'deleted' AND u.created_at >= :from_utc AND u.created_at < :to_utc
             ORDER BY u.created_at DESC, u.user_id DESC
             LIMIT 8",
            $params
        );

        return [
            'date_from'        => $range['date_from'],
            'date_to'          => $range['date_to'],
            'counts'           => $counts,
            'new_per_day'      => $new_per_day,
            'buyer_mix'        => $buyer_mix,
            'top_customers'    => $top_customers,
            'newest_customers' => $newest_customers,
        ];
    }

    // ------------------------------------------------------------------ Stock tab

    private const STOCK_LIST_SIZE = 10;
    /**
     * The Stock tab: current counts, best_sellers (pieces sold during the selected period, top 8),
     * slow_movers (in stock but not sold during the period) and offers_ending
     * (running offers, ending soonest first).
     */
    public function getStockStats(array $range): array
    {
        $threshold = ProductEditor::LOW_STOCK_THRESHOLD;
        $params = $this->rangeParams($range);

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
             WHERE o.order_status NOT IN ('cancelled', 'expired') AND o.order_placed_at >= :from_utc AND o.order_placed_at < :to_utc
             GROUP BY i.product_id
             ORDER BY pieces_sold DESC
             LIMIT 8",
            $params
        );

        $slow_movers = $this->db->fetchAll(
            "SELECT p.product_id, p.product_name, p.product_sku, p.product_stock_quantity, p.product_unit_label,
                    (SELECT MAX(o.order_placed_at) FROM order_items i JOIN orders o ON o.order_id = i.order_id
                      WHERE i.product_id = p.product_id AND o.order_status NOT IN ('cancelled', 'expired')) AS last_sold_at
             FROM products p
             WHERE p.deleted_at IS NULL AND p.product_is_active = 1 AND p.product_stock_quantity > 0
               AND NOT EXISTS (SELECT 1 FROM order_items i JOIN orders o ON o.order_id = i.order_id
                               WHERE i.product_id = p.product_id AND o.order_status NOT IN ('cancelled', 'expired')
                                  AND o.order_placed_at >= :not_from_utc AND o.order_placed_at < :not_to_utc)
             ORDER BY p.product_stock_quantity DESC, p.product_name
             LIMIT " . self::STOCK_LIST_SIZE,
            ['not_from_utc' => $params['from_utc'], 'not_to_utc' => $params['to_utc']]
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
                (SELECT COUNT(*) FROM orders o WHERE " . Payment::REFUND_DUE_SQL . ")             AS refunds_due,
                (SELECT COUNT(*) FROM orders WHERE order_status = 'confirmed'
                  AND order_payment_status NOT IN ('balance_due', 'pending'))                        AS orders_to_pack,
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

    /** Order status counts for the selected placed-date range. */
    public function getOrderStatusCounts(array $range): array
    {
        $rows = $this->db->fetchAll(
            'SELECT order_status, COUNT(*) AS order_count FROM orders
             WHERE order_placed_at >= :from_utc AND order_placed_at < :to_utc GROUP BY order_status',
            $this->rangeParams($range)
        );
        return array_map('intval', array_column($rows, 'order_count', 'order_status'));
    }

    /** Latest orders placed during the selected period, for the Overview preview. */
    public function getRecentOrders(array $range, int $limit): array
    {
        $limit = max(1, min($limit, 20));
        return $this->db->fetchAll(
            'SELECT o.order_id, o.order_number, o.order_placed_at, o.order_status, u.user_full_name
             FROM orders o JOIN users u ON u.user_id = o.user_id
             WHERE o.order_placed_at >= :from_utc AND o.order_placed_at < :to_utc
             ORDER BY o.order_placed_at DESC, o.order_id DESC LIMIT ' . $limit,
            $this->rangeParams($range)
        );
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
     * fewest pieces first: ['items' => up to 10 rows, 'low_count' => â€¦, 'out_count' => â€¦].
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

    /** Convert selected Tanzania calendar days to UTC database boundaries. */
    private function rangeParams(array $range): array
    {
        return [
            'from_utc' => localTimeToUtc($range['date_from'] . ' 00:00:00'),
            'to_utc' => localTimeToUtc($range['date_to'] . ' 00:00:00 +1 day'),
        ];
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
