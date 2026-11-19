<?php

require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('products.manage');
$page_title = 'Product requests';
$active_menu = 'product_requests';
$db = Database::instance();
$requests = new ProductRequest($db);
$product_search = trim((string) ($_GET['q'] ?? ''));

if (isPostRequest()) {
    Csrf::verifyOrFail($_POST['csrf_token'] ?? null);
    try {
        $requests->matchRequest((int) ($_POST['product_request_id'] ?? 0), (int) ($_POST['matched_product_id'] ?? 0), (int) $current_admin['admin_id']);
        Session::flash('success', 'Request matched. The customer will be notified when the product is in stock.');
    } catch (ApiException $exception) {
        Session::flash('error', $exception->getMessage());
    }
    redirect(url('admin/product_requests.php'));
}

$items = $requests->getRequestsForAdmin();
$product_page = (new ProductEditor($db))->getProductsForAdmin([
    'status' => 'active',
    'per_page' => 100,
    'q' => $product_search,
]);
$products = $product_page['items'];

require __DIR__ . '/includes/header.php';
?>
<main class="container-fluid py-4">
    <div class="mb-4">
        <h2 class="h4 mb-1">Customer product requests</h2>
        <p class="text-muted mb-0">Match a request to a catalog product. Customers receive an in-app and push notification once it is in stock.</p>
    </div>

    <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-12 col-md-5">
            <label for="product-search" class="form-label">Find a product to match</label>
            <input type="search" id="product-search" name="q" value="<?= e($product_search) ?>" class="form-control" placeholder="Product name, brand or SKU">
        </div>
        <div class="col-auto"><button class="btn btn-outline-primary" type="submit">Search products</button></div>
        <?php if ($product_search !== ''): ?><div class="col-auto"><a class="btn btn-link" href="<?= e(url('admin/product_requests.php')) ?>">Clear</a></div><?php endif; ?>
    </form>

    <?php if ($items === []): ?>
        <div class="alert alert-light border">There are no requests waiting for review.</div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($items as $item): ?>
                <div class="col-12 col-xl-6">
                    <article class="card h-100">
                        <div class="card-body">
                            <div class="d-flex gap-3">
                                <?php if ($item['product_request_image_path']): ?>
                                    <img src="<?= e(url('admin/product_request_image.php?id=' . (int) $item['product_request_id'])) ?>" alt="Customer reference" class="rounded object-fit-cover" width="96" height="96">
                                <?php endif; ?>
                                <div class="flex-grow-1">
                                    <h3 class="h6 mb-1"><?= e($item['product_request_description']) ?></h3>
                                    <div class="small text-muted"><?= e($item['user_full_name'] ?: 'Customer') ?> · <?= e($item['user_phone']) ?></div>
                                    <div class="small text-muted">Request #<?= e($item['product_request_id']) ?> · <?= e($item['product_request_status']) ?></div>
                                    <?php if ($item['matched_product_name']): ?>
                                        <div class="small mt-2">Currently matched: <strong><?= e($item['matched_product_name']) ?></strong></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <form method="post" class="row g-2 align-items-end mt-2">
                                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                                <input type="hidden" name="product_request_id" value="<?= e($item['product_request_id']) ?>">
                                <div class="col">
                                    <label class="form-label" for="product-<?= e($item['product_request_id']) ?>">Match to product</label>
                                    <select class="form-select" id="product-<?= e($item['product_request_id']) ?>" name="matched_product_id" required>
                                        <option value="">Choose a product</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?= e($product['product_id']) ?>" <?= (int) $item['matched_product_id'] === (int) $product['product_id'] ? 'selected' : '' ?>><?= e($product['product_name']) ?><?= (int) $product['product_stock_quantity'] > 0 ? ' · In stock' : ' · Out of stock' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-auto"><button class="btn btn-primary" type="submit">Save match</button></div>
                            </form>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
