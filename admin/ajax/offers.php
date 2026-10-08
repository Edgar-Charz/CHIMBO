<?php

/**
 * Rows for the offers tables on admin/offers.php (server-side DataTables).
 * ?tab=running|scheduled|ended — the tab's status, and where the row buttons send their forms back to.
 */

require dirname(__DIR__) . '/includes/admin_bootstrap.php';

AdminSession::requireAjaxLogin('products.manage');

$tab       = in_array($_GET['tab'] ?? '', ProductOffer::STATUSES, true) ? $_GET['tab'] : 'running';
$list_page = url("admin/offers.php?tab={$tab}");

try {
    $pagination = (new ProductOffer(Database::instance()))->getOffersForAdmin(['status' => $tab] + adminDataTablesInput($_GET, 'search'));
} catch (ApiException $e) {
    adminDataTablesError($e);
}

adminDataTablesJson($pagination, function (array $offer) use ($list_page): array {
    $offer_id = (int) $offer['product_offer_id'];
    $percent  = (int) $offer['product_offer_percent'];
    $status   = $offer['product_offer_status'];

    return [
        'product' => '<a class="fw-semibold" href="' . e(url('admin/product_edit.php?id=' . $offer['product_id'])) . '">' . e($offer['product_name']) . '</a>',
        'percent' => '<span class="status-badge status-danger">−' . e($percent) . '%</span>',
        'price'   => e(adminOfferPrice((int) $offer['product_price'], $percent))
            . ((int) $offer['product_price_from'] !== (int) $offer['product_price']
                ? '<div class="small text-muted">best price ' . e(adminOfferPrice((int) $offer['product_price_from'], $percent)) . '</div>'
                : ''),
        'starts'  => e(adminDateTime($offer['product_offer_starts_at'])),
        'ends'    => e(adminDateTime($offer['product_offer_ends_at'])),
        'by'      => e($offer['created_by_admin_name'] ?? '—'),
        'status'  => adminStatusBadge($status),
        'actions' => adminRowActions(
            $status !== 'ended' ? adminActionLink('bi-pencil', 'Edit', url("admin/offer_edit.php?id={$offer_id}")) : '',
            match ($status) {
                'running'   => adminActionButton('bi-stop-circle', 'End now', 'end_offer', $offer_id,
                    "End the {$percent}% offer on {$offer['product_name']} now? Customers pay the normal price at once.", $list_page, true),
                'scheduled' => adminActionButton('bi-x-circle', 'Cancel', 'end_offer', $offer_id,
                    "Cancel the {$percent}% offer on {$offer['product_name']}? It will not start.", $list_page, true),
                default     => '',
            },
        ),
    ];
}, '');   // plain rows: the product link and the buttons are separate
