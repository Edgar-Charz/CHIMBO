<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin  = AdminSession::requireLogin('categories.manage');
$admin_id       = (int) $current_admin['admin_id'];
$category_model = new Category(Database::instance());

$category_id = (int) ($_GET['id'] ?? 0);
$is_new      = $category_id === 0;
$category    = $is_new ? null : adminLoadOrRedirect(fn () => $category_model->getCategoryForAdmin($category_id), 'categories.php');

$form_error = adminHandleForm(function (string $form_action) use ($category_model, $category_id, $is_new, $admin_id): void {
    $edit_page = "admin/category_edit.php?id={$category_id}";

    if ($form_action === 'delete') {
        $category_model->deleteCategory($category_id, $admin_id);
        Session::flash('success', 'Category deleted.');
        redirect(url('admin/categories.php'));
    }
    if ($form_action === 'upload_image') {
        $category_model->setCategoryImage($category_id, $_FILES['category_image'] ?? [], $admin_id);
        Session::flash('success', 'Picture updated.');
        redirect(url($edit_page));
    }
    if ($is_new) {
        $new_category_id = $category_model->createCategory($_POST, $admin_id);
        Session::flash('success', 'Category created. You can add a picture below.');
        redirect(url("admin/category_edit.php?id={$new_category_id}"));
    }
    $category_model->updateCategory($category_id, $_POST, $admin_id);
    Session::flash('success', 'Category saved.');
    redirect(url($edit_page));
});

$errors = $form_error?->fields() ?? [];
$form   = adminFormValues($form_error, $category ?? ['category_is_active' => 1, 'category_sort_order' => 0]);

// A top category can't be its own parent
$parent_options = array_filter(
    $category_model->getTopCategories(),
    fn (array $top_category): bool => (int) $top_category['category_id'] !== $category_id
);

$page_title  = $is_new ? 'Add category' : $category['category_name'];
$active_menu = 'categories';
require __DIR__ . '/includes/header.php';
?>

<a class="admin-back-link" href="<?= e(url('admin/categories.php')) ?>"><i class="bi bi-arrow-left"></i> Categories</a>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form class="admin-panel" method="post" novalidate>
            <?= Csrf::field() ?>
            <div class="admin-panel-header"><h2 class="admin-panel-title">Details</h2></div>
            <div class="admin-panel-body">
                <div class="mb-3">
                    <label class="form-label" for="category_name">Name</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'category_name') ?>" id="category_name" name="category_name"
                           value="<?= e($form['category_name'] ?? '') ?>" maxlength="80" required>
                    <?= adminFieldError($errors, 'category_name') ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="parent_category_id">Parent category</label>
                    <select class="form-select<?= adminInvalidClass($errors, 'parent_category_id') ?>" id="parent_category_id" name="parent_category_id">
                        <option value="">None — this is a top category</option>
                        <?php foreach ($parent_options as $top_category): ?>
                            <option value="<?= e($top_category['category_id']) ?>" <?= adminSelected($form, 'parent_category_id', $top_category['category_id']) ?>>
                                <?= e($top_category['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Choose a parent to make this a sub-category (e.g. Skin Care under Cosmetics). Products go in sub-categories.</div>
                    <?= adminFieldError($errors, 'parent_category_id') ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="category_tagline">Tagline</label>
                    <input class="form-control<?= adminInvalidClass($errors, 'category_tagline') ?>" id="category_tagline" name="category_tagline"
                           value="<?= e($form['category_tagline'] ?? '') ?>" maxlength="120" placeholder="e.g. Shamba la Vipodozi">
                    <div class="form-text">Shown under the name on the Home card (Kiswahili).</div>
                    <?= adminFieldError($errors, 'category_tagline') ?>
                </div>

                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="category_sort_order">Display order</label>
                        <input class="form-control<?= adminInvalidClass($errors, 'category_sort_order') ?>" type="number" min="0" max="1000"
                               id="category_sort_order" name="category_sort_order" value="<?= e($form['category_sort_order'] ?? 0) ?>">
                        <div class="form-text">Lower numbers come first.</div>
                        <?= adminFieldError($errors, 'category_sort_order') ?>
                    </div>
                    <div class="col-sm-6 d-flex align-items-center">
                        <div class="form-check form-switch mt-sm-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="category_is_active" name="category_is_active"
                                   value="1" <?= adminChecked($form, 'category_is_active') ?>>
                            <label class="form-check-label" for="category_is_active">Visible in the shop</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="admin-panel-footer">
                <a class="btn btn-light" href="<?= e(url('admin/categories.php')) ?>">Cancel</a>
                <button class="btn btn-chimbo" type="submit" name="form_action" value="save">
                    <?= $is_new ? 'Create category' : 'Save changes' ?>
                </button>
            </div>
        </form>
    </div>

    <?php if (!$is_new): ?>
        <div class="col-lg-4">
            <form class="admin-panel mb-3" method="post" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <div class="admin-panel-header"><h2 class="admin-panel-title">Picture</h2></div>
                <div class="admin-panel-body">
                    <?php if ($category['category_image_path']): ?>
                        <img class="admin-preview-image admin-content-image-preview mb-3" src="<?= e(url($category['category_image_path'])) ?>" alt="">
                    <?php else: ?>
                        <p class="text-muted small">No picture yet. Top categories show it on the Home card.</p>
                    <?php endif; ?>
                    <input class="form-control mb-2" type="file" name="category_image" accept="image/jpeg,image/png,image/webp" required>
                    <div class="form-text mb-3">JPG, PNG or WEBP, up to 8 MB.</div>
                    <button class="btn btn-outline-secondary w-100" type="submit" name="form_action" value="upload_image">
                        <i class="bi bi-upload"></i> <?= $category['category_image_path'] ? 'Replace picture' : 'Upload picture' ?>
                    </button>
                </div>
            </form>

            <form class="admin-panel admin-danger-zone" method="post" data-confirm="Delete this category? This cannot be undone.">
                <?= Csrf::field() ?>
                <div class="admin-panel-body">
                    <h2 class="admin-panel-title mb-1">Delete category</h2>
                    <p class="small text-muted">Only possible when it has no sub-categories and no products. Otherwise, switch off "Visible in the shop".</p>
                    <button class="btn btn-outline-danger w-100" type="submit" name="form_action" value="delete">Delete category</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
