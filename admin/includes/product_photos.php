<?php

/**
 * Product page — "Photos" panel: upload, reorder, choose the main photo, delete.
 * Included by product_edit.php after its main form (forms can't be nested). Uses $is_new, $product_id,
 * $product_images and $errors.
 */

$photos      = $is_new ? [] : $product_images->getImages($product_id);
$photo_count = count($photos);
$can_upload  = $photo_count < ProductImage::MAX_IMAGES_PER_PRODUCT;
?>
<div class="admin-panel" id="photos">
    <div class="admin-panel-header">
        <h2 class="admin-panel-title">Photos</h2>
        <?php if (!$is_new): ?>
            <span class="small text-muted"><?= e($photo_count) ?> of <?= e(ProductImage::MAX_IMAGES_PER_PRODUCT) ?></span>
        <?php endif; ?>
    </div>
    <div class="admin-panel-body">
        <?php if ($is_new): ?>
            <p class="text-muted mb-0"><i class="bi bi-info-circle"></i> Create the product first, then add its photos here.</p>
        <?php else: ?>
            <?php if ($photos): ?>
                <div class="photo-grid mb-3">
                    <?php foreach ($photos as $position => $photo): ?>
                        <form class="photo-card <?= $photo['product_image_is_primary'] ? 'is-main' : '' ?>" method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="product_image_id" value="<?= e($photo['product_image_id']) ?>">
                            <a href="<?= e($photo['product_image_large_url']) ?>" target="_blank" rel="noopener" title="Open full size">
                                <img src="<?= e($photo['product_image_thumb_url']) ?>" alt="Photo <?= e($position + 1) ?>" loading="lazy">
                            </a>
                            <?php if ($photo['product_image_is_primary']): ?>
                                <span class="photo-main-badge">Main</span>
                            <?php endif; ?>
                            <div class="photo-actions">
                                <button class="btn btn-sm btn-light" type="submit" name="form_action" value="move_image_up"
                                    title="Move left" aria-label="Move left" <?= $position === 0 ? 'disabled' : '' ?>><i class="bi bi-arrow-left"></i></button>
                                <?php if (!$photo['product_image_is_primary']): ?>
                                    <button class="btn btn-sm btn-light" type="submit" name="form_action" value="set_main_image"
                                        title="Make main photo" aria-label="Make main photo"><i class="bi bi-star"></i></button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-light text-danger" type="submit" name="form_action" value="delete_image"
                                    title="Delete" aria-label="Delete photo" data-confirm="Delete this photo?"><i class="bi bi-trash"></i></button>
                                <button class="btn btn-sm btn-light" type="submit" name="form_action" value="move_image_down"
                                    title="Move right" aria-label="Move right" <?= $position === $photo_count - 1 ? 'disabled' : '' ?>><i class="bi bi-arrow-right"></i></button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
                <p class="form-text">The main photo (★) is shown on product cards. The order here is the order in the app's gallery.</p>
            <?php endif; ?>

            <?php if ($can_upload): ?>
                <form class="photo-upload" method="post" enctype="multipart/form-data">
                    <?= Csrf::field() ?>
                    <input class="form-control<?= adminInvalidClass($errors, 'product_image') ?>" type="file" name="product_image"
                        accept="image/jpeg,image/png,image/webp" required aria-label="Choose a photo">
                    <button class="btn btn-outline-secondary text-nowrap" type="submit" name="form_action" value="upload_image">
                        <i class="bi bi-upload"></i> Upload photo
                    </button>
                </form>
                <div class="form-text">JPG, PNG or WEBP, up to 8 MB, at least 200 px. <?= $photos ? '' : 'The first photo becomes the main one.' ?></div>
                <?= adminFieldError($errors, 'product_image') ?>
            <?php else: ?>
                <p class="text-muted small mb-0">This product has the maximum of <?= e(ProductImage::MAX_IMAGES_PER_PRODUCT) ?> photos. Delete one to add another.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>