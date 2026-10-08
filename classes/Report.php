<?php

/**
 * Sales reports for the admin "Reports" page (permission reports.view) and their CSV downloads.
 *
 * Counted orders = every order except cancelled and expired ones, by the day it was placed
 * (East Africa time). Money is whole shillings.
 *
 * How to use it:
 *   $report = new Report(Database::instance());
 *   $data = $report->getSalesReport($_GET);                  // date_from, date_to (Y-m-d), default the last 30 days
 *   $file = $report->exportCsv('by_day', $_GET);             // ['file_name' => ..., 'content' => ...]
 */
class Report
{
    public const SECTIONS = ['by_day', 'by_category', 'top_products', 'by_payment_method', 'by_region'];

    private const DEFAULT_DAYS     = 30;
    private const MAX_DAYS         = 366;
    private const TOP_PRODUCTS     = 20;
    private const COUNTED_ORDERS   = "o.order_status NOT IN ('cancelled', 'expired')";

    // Column titles for each CSV download: result key => title
    private const CSV_COLUMNS = [
        'by_day'            => ['sales_date' => 'Date', 'order_count' => 'Orders', 'revenue' => 'Revenue (TZS)'],
        'by_category'       => ['category_name' => 'Category', 'quantity' => 'Pieces sold', 'revenue' => 'Revenue (TZS)'],
        'top_products'      => ['product_name' => 'Product', 'product_sku' => 'SKU', 'quantity' => 'Pieces sold', 'revenue' => 'Revenue (TZS)'],
        'by_payment_method' => ['order_payment_method' => 'Payment method', 'order_count' => 'Orders', 'revenue' => 'Total (TZS)', 'paid_total' => 'Paid (TZS)'],
        'by_region'         => ['region_name' => 'Region', 'order_count' => 'Orders', 'revenue' => 'Revenue (TZS)'],
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * Everything the Reports page shows for the chosen days:
     * summary {order_count, revenue, average_order, pieces_sold, delivery_fees, cancelled_count},
     * by_day, by_category, top_products, by_payment_method, by_region, and the date_from / date_to used.
     */
    public function getSalesReport(array $filters): array
    {
        [$date_from, $date_to, $params] = $this->dateRange($filters);

        return [
            'date_from'         => $date_from,
            'date_to'           => $date_to,
            'summary'           => $this->getSummary($params),
            'by_day'            => $this->getSalesByDay($params),
            'by_category'       => $this->getSalesByCategory($params),
            'top_products'      => $this->getTopProducts($params),
            'by_payment_method' => $this->getSalesByPaymentMethod($params),
            'by_region'         => $this->getSalesByRegion($params),
        ];
    }

    /** One section as a CSV file (opens in Excel). $section is one of SECTIONS. */
    public function exportCsv(string $section, array $filters): array
    {
        if (!in_array($section, self::SECTIONS, true)) {
            throw ApiException::validation(['section' => 'Unknown report.']);
        }
        $report  = $this->getSalesReport($filters);
        $columns = self::CSV_COLUMNS[$section];

        $file = fopen('php://temp', 'r+');
        fwrite($file, "\xEF\xBB\xBF");   // tells Excel the file is UTF-8
        fputcsv($file, array_values($columns));
        foreach ($report[$section] as $row) {
            fputcsv($file, array_map(fn (string $key) => $row[$key], array_keys($columns)));
        }
        rewind($file);
        $content = stream_get_contents($file);
        fclose($file);

        return [
            'file_name' => "chimbo-{$section}-{$report['date_from']}-to-{$report['date_to']}.csv",
            'content'   => $content,
        ];
    }

    private function getSummary(array $params): array
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS order_count,
                    COALESCE(SUM(o.order_total), 0) AS revenue,
                    COALESCE(SUM(o.order_delivery_fee), 0) AS delivery_fees,
                    (SELECT COALESCE(SUM(i.order_item_quantity), 0)
                     FROM order_items i JOIN orders o ON o.order_id = i.order_id
                     WHERE " . self::COUNTED_ORDERS . ' AND o.order_placed_at >= :from_utc_items AND o.order_placed_at < :to_utc_items) AS pieces_sold,
                    (SELECT COUNT(*) FROM orders o
                     WHERE o.order_status IN (\'cancelled\', \'expired\')
                       AND o.order_placed_at >= :from_utc_cancelled AND o.order_placed_at < :to_utc_cancelled) AS cancelled_count
             FROM orders o
             WHERE ' . self::COUNTED_ORDERS . ' AND o.order_placed_at >= :from_utc AND o.order_placed_at < :to_utc',
            $params + [
                'from_utc_items'     => $params['from_utc'], 'to_utc_items'     => $params['to_utc'],
                'from_utc_cancelled' => $params['from_utc'], 'to_utc_cancelled' => $params['to_utc'],
            ]
        );

        $summary = array_map('intval', $row);
        $summary['average_order'] = $summary['order_count'] > 0 ? intdiv($summary['revenue'], $summary['order_count']) : 0;
        return $summary;
    }

    /** One row per day that had orders, oldest first. */
    private function getSalesByDay(array $params): array
    {
        $offset = (new DateTimeImmutable('now', $this->timezone()))->format('P');   // e.g. "+03:00"

        return $this->toNumbers($this->db->fetchAll(
            "SELECT DATE(CONVERT_TZ(o.order_placed_at, '+00:00', '{$offset}')) AS sales_date,
                    COUNT(*) AS order_count, SUM(o.order_total) AS revenue
             FROM orders o
             WHERE " . self::COUNTED_ORDERS . ' AND o.order_placed_at >= :from_utc AND o.order_placed_at < :to_utc
             GROUP BY sales_date
             ORDER BY sales_date',
            $params
        ), ['order_count', 'revenue']);
    }

    /** By top category (Cosmetics, Jewelry …): pieces and money from the order lines. */
    private function getSalesByCategory(array $params): array
    {
        return $this->toNumbers($this->db->fetchAll(
            'SELECT COALESCE(parent.category_name, c.category_name) AS category_name,
                    SUM(i.order_item_quantity) AS quantity, SUM(i.order_item_line_total) AS revenue
             FROM order_items i
             JOIN orders o     ON o.order_id = i.order_id
             JOIN products p   ON p.product_id = i.product_id
             JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN categories parent ON parent.category_id = c.parent_category_id
             WHERE ' . self::COUNTED_ORDERS . ' AND o.order_placed_at >= :from_utc AND o.order_placed_at < :to_utc
             GROUP BY category_name
             ORDER BY revenue DESC',
            $params
        ), ['quantity', 'revenue']);
    }

    /** The best-selling products by money, with the name and SKU as they were when ordered. */
    private function getTopProducts(array $params): array
    {
        return $this->toNumbers($this->db->fetchAll(
            'SELECT i.product_id, MAX(i.order_item_product_name) AS product_name, MAX(i.order_item_sku) AS product_sku,
                    SUM(i.order_item_quantity) AS quantity, SUM(i.order_item_line_total) AS revenue
             FROM order_items i
             JOIN orders o ON o.order_id = i.order_id
             WHERE ' . self::COUNTED_ORDERS . ' AND o.order_placed_at >= :from_utc AND o.order_placed_at < :to_utc
             GROUP BY i.product_id
             ORDER BY revenue DESC
             LIMIT ' . self::TOP_PRODUCTS,
            $params
        ), ['product_id', 'quantity', 'revenue']);
    }

    /** Per payment method: all counted orders, and how much of it is already paid. */
    private function getSalesByPaymentMethod(array $params): array
    {
        return $this->toNumbers($this->db->fetchAll(
            "SELECT o.order_payment_method, COUNT(*) AS order_count, SUM(o.order_total) AS revenue,
                    SUM(IF(o.order_payment_status = 'paid', o.order_total, 0)) AS paid_total
             FROM orders o
             WHERE " . self::COUNTED_ORDERS . ' AND o.order_placed_at >= :from_utc AND o.order_placed_at < :to_utc
             GROUP BY o.order_payment_method
             ORDER BY revenue DESC',
            $params
        ), ['order_count', 'revenue', 'paid_total']);
    }

    /** Per delivery region. */
    private function getSalesByRegion(array $params): array
    {
        return $this->toNumbers($this->db->fetchAll(
            'SELECT o.order_ship_region_name AS region_name, COUNT(*) AS order_count, SUM(o.order_total) AS revenue
             FROM orders o
             WHERE ' . self::COUNTED_ORDERS . ' AND o.order_placed_at >= :from_utc AND o.order_placed_at < :to_utc
             GROUP BY o.order_ship_region_name
             ORDER BY revenue DESC',
            $params
        ), ['order_count', 'revenue']);
    }

    /**
     * The chosen days (East Africa time) → [date_from, date_to, query values in UTC].
     * Default: the last 30 days up to today. At most one year at a time.
     */
    private function dateRange(array $filters): array
    {
        $data = Validator::validate($filters, ['date_from' => 'nullable|date', 'date_to' => 'nullable|date']);

        $today     = new DateTimeImmutable('today', $this->timezone());
        $date_to   = isset($data['date_to']) ? new DateTimeImmutable($data['date_to'], $this->timezone()) : $today;
        $date_from = isset($data['date_from'])
            ? new DateTimeImmutable($data['date_from'], $this->timezone())
            : $date_to->modify('-' . (self::DEFAULT_DAYS - 1) . ' days');

        if ($date_from > $date_to) {
            throw ApiException::validation(['date_from' => 'The start date must be on or before the end date.']);
        }
        if ($date_from->diff($date_to)->days >= self::MAX_DAYS) {
            throw ApiException::validation(['date_from' => 'Choose at most one year at a time.']);
        }

        $utc = new DateTimeZone('UTC');
        return [
            $date_from->format('Y-m-d'),
            $date_to->format('Y-m-d'),
            [
                'from_utc' => $date_from->setTimezone($utc)->format('Y-m-d H:i:s'),
                'to_utc'   => $date_to->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
            ],
        ];
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) Env::get('APP_TIMEZONE', 'Africa/Dar_es_Salaam'));
    }

    /** MySQL returns sums as text; turn these columns into whole numbers. */
    private function toNumbers(array $rows, array $number_columns): array
    {
        foreach ($rows as &$row) {
            foreach ($number_columns as $column) {
                $row[$column] = (int) $row[$column];
            }
        }
        unset($row);
        return $rows;
    }
}
