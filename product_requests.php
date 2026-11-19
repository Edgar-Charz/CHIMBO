<?php

/** A customer's open and fulfilled requests for products not yet in the shop. */
require __DIR__ . '/includes/init.php';

requireCustomer();
$product_requests = (new ProductRequest(Database::instance()))->getRequestsForUser(
    (int) CustomerSession::currentUserId()
);
if ($product_requests === []) {
    header('Location: ' . url('account.php'), true, 302);
    exit;
}

$request_status_labels = [
    'pending' => ['label' => 'Inasubiri kuchunguzwa', 'class' => 'is-pending'],
    'matched' => ['label' => 'Bidhaa inatafutwa', 'class' => 'is-matched'],
    'notified' => ['label' => 'Inapatikana', 'class' => 'is-notified'],
];
$page = [
    'title' => 'Maombi ya bidhaa',
    'description' => 'Angalia hali ya maombi yako ya bidhaa.',
    'nav' => 'account',
    'scripts' => ['product_requests.js'],
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl product-requests-page">
    <header class="product-requests-heading">
        <div>
            <h1 class="h2 mb-1">Maombi ya bidhaa</h1>
            <p class="text-secondary mb-0">Tutaendelea kukujulisha bidhaa ikiwekwa dukani.</p>
        </div>
        <a class="btn btn-outline-primary" href="<?= e(url('account.php')) ?>">Rudi Wasifu</a>
    </header>

    <div class="product-requests-list" data-product-requests>
        <?php foreach ($product_requests as $product_request) :
            $status = $request_status_labels[$product_request['product_request_status']] ?? $request_status_labels['pending'];
            $request_id = (int) $product_request['product_request_id'];
            ?>
            <article class="product-request-card" data-product-request data-request-id="<?= e($request_id) ?>">
                <div class="product-request-card-heading">
                    <span class="product-request-status <?= e($status['class']) ?>">
                        <?= e($status['label']) ?>
                    </span>
                    <time datetime="<?= e($product_request['created_at']) ?>">
                        <?= e(date('j M Y', strtotime($product_request['created_at']))) ?>
                    </time>
                </div>
                <p class="product-request-description"><?= e($product_request['product_request_description']) ?></p>
                <?php if ($product_request['product_request_image_url'] !== null
                    || $product_request['matched_product_image_url'] !== null) : ?>
                    <div class="product-request-photos">
                        <?php if ($product_request['product_request_image_url'] !== null) : ?>
                            <figure>
                                <a href="<?= e($product_request['product_request_image_url']) ?>" target="_blank" rel="noopener">
                                    <img src="<?= e($product_request['product_request_image_url']) ?>" alt="Picha uliyotuma" loading="lazy">
                                </a>
                                <figcaption>Picha uliyotuma</figcaption>
                            </figure>
                        <?php endif; ?>
                        <?php if ($product_request['matched_product_image_url'] !== null) : ?>
                            <figure>
                                <a href="<?= e(productUrl((int) $product_request['matched_product_id'])) ?>">
                                    <img src="<?= e($product_request['matched_product_image_url']) ?>" alt="<?= e($product_request['matched_product_name'] ?? 'Bidhaa inayolingana') ?>" loading="lazy">
                                </a>
                                <figcaption>Picha ya bidhaa dukani</figcaption>
                            </figure>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($product_request['matched_product_id'])) : ?>
                    <a class="product-request-match" href="<?= e(productUrl((int) $product_request['matched_product_id'])) ?>">
                        <i class="bi bi-box-seam" aria-hidden="true"></i>
                        <?= e($product_request['matched_product_name'] ?? 'Bidhaa inayolingana') ?>
                    </a>
                <?php endif; ?>
                <?php if (in_array($product_request['product_request_status'], ['pending', 'matched'], true)) : ?>
                    <div class="product-request-actions">
                        <button class="btn btn-outline-secondary" type="button" data-cancel-request>
                            Ondoa ombi
                        </button>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
