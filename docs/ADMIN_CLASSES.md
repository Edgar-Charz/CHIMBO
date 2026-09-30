# Classes for the admin pages (catalog)

Built by the backend session. Admin pages call these methods directly (no API). All tested (`tests/Integration/CatalogAdminTest.php`, `ProductPhotoTest.php`).

## How to use them in a page
```php
$current_admin = AdminSession::requireLogin('products.manage');
$product_editor = new ProductEditor(Database::instance());

if (isPostRequest()) {
    Csrf::verifyOrFail($_POST['csrf_token'] ?? null);
    try {
        $product_id = $product_editor->createProduct($_POST, $current_admin['admin_id']);
        Session::flash('success', 'Product saved.');
        redirect(url("admin/product_edit.php?id={$product_id}"));
    } catch (ApiException $e) {
        $errors = $e->fields();        // ['field_name' => 'message'] → show under each input
        $error_message = $e->getMessage();
    }
}
```
- **Form field names = the keys below** (= database column names). Extra fields (e.g. `csrf_token`) are ignored.
- **Checkboxes** (`*_is_active`, `*_is_verified`, `product_is_bestseller`): unticked sends nothing → saved as 0. Use `value="1"`.
- Every change is written to `audit_logs` automatically.
- Messages from `ApiException` are ready to show (validation messages from `Validator` are Kiswahili, the rest English).

## Category — `new Category($db)` · permission `categories.manage`
| Method | Notes |
|---|---|
| `getAllCategoriesForAdmin()` | top categories each followed by their chips; `parent_category_name`, `product_count` |
| `getTopCategories()` | for the "parent" dropdown |
| `getCategoryForAdmin($id)` | row for the edit form · 404 |
| `createCategory($input, $admin_id)` → id | fields: `category_name`*, `parent_category_id` (empty = top category), `category_tagline`, `category_sort_order`, `category_is_active` |
| `updateCategory($id, $input, $admin_id)` | same fields |
| `setCategoryImage($id, $_FILES['category_image'], $admin_id)` | picture for the Home card |
| `deleteCategory($id, $admin_id)` | only when empty; otherwise 409 `CATEGORY_IN_USE` → tell staff to hide it instead |
Rule: two levels only (a parent must be a top category). Products go in chips.

## Seller — `new Seller($db)` · permission `sellers.manage`
| Method | Notes |
|---|---|
| `getAllSellersForAdmin()` | with `product_count` |
| `getActiveSellers()` | for the product form dropdown |
| `getSellerForAdmin($id)` · `createSeller($input, $admin_id)` → id · `updateSeller($id, $input, $admin_id)` | fields: `seller_name`*, `seller_description`, `seller_phone`, `seller_is_verified`, `seller_status`* (`active`/`inactive` — inactive hides all its products) |

## Products — `new ProductEditor($db)` · permission `products.manage` / `inventory.manage`
| Method | Notes |
|---|---|
| `getProductsForAdmin($_GET)` | filters `q` (name/brand/SKU), `category_id`, `seller_id`, `status` (`active`/`hidden`), `stock` (`low`/`out`), `page`, `per_page` (`ProductEditor::PER_PAGE_OPTIONS` = 10/25/50/100, default 25), `sort` (`product_name`/`product_price`/`product_stock_quantity`/`created_at`) + `direction` (`asc`/`desc`, default `desc`; no sort = newest first) → `['items', 'total', 'page', 'per_page']`; items include `product_price`, `product_price_from`, `product_image_thumb_path` (use `url()`), names |
| `getProductForAdmin($id)` | row + `tiers` + `images` · 404 |
| `createProduct($input, $admin_id)` → id | fields below + `product_stock_quantity` (opening stock) |
| `updateProduct($id, $input, $admin_id)` | fields below (stock is NOT changed here) |
| `adjustStock($id, $input, $admin_id)` → new stock | `movement_quantity_change`* (e.g. 50 or -3), `movement_reason`* (`restock`/`adjustment`/`return`), `movement_note` |
| `getStockMovements($id, $limit = 50)` | history with `admin_full_name` |
| `deleteProduct($id, $admin_id)` | hides it from shop and admin (row kept for history) |
Constants: `ProductEditor::UNIT_LABELS`, `ProductEditor::LOW_STOCK_THRESHOLD` (20).

Product fields: `product_name`*, `seller_id`*, `category_id`* (a chip), `product_brand`, `product_description`, `product_sku` (empty → auto `CHB-XXXXXX`), `product_unit_label`* (`pc`, `pack`, `set`, `box`, `dozen`, `carton`), `product_moq`*, `product_compare_at_price` (old price → "Ofa"; must be higher than the normal price), `product_is_bestseller`, `product_new_until` (date `YYYY-MM-DD` → "Bidhaa Mpya"), `product_delivery_days_min`*, `product_delivery_days_max`*, `product_is_active`, **`tiers`***.

**Tier editor:** send rows as `tiers[0][tier_min_quantity]`, `tiers[0][tier_unit_price]`, `tiers[1][…]` … (empty rows are ignored; up to 10).
Rules: the first level starts **at the MOQ**; each next level has a **bigger quantity and a lower price**. Errors come under the key `tiers`.

## Photos — `new ProductImage($db)` · permission `products.manage`
`getImages($product_id)` · `addImage($product_id, $_FILES['product_image'], $admin_id)` · `setMainImage($product_id, $image_id, $admin_id)` · `reorderImages($product_id, [$id1, $id2, …], $admin_id)` · `deleteImage($product_id, $image_id, $admin_id)`.
Max 8 photos; JPG/PNG/WEBP up to 8 MB, 200–6000 px; the first photo becomes the main one. Form needs `enctype="multipart/form-data"`. Each call returns the updated gallery (with `product_image_thumb_url` etc.).

## Banner — `new Banner($db)` · permission `banners.manage`
`getAllBannersForAdmin()` · `getBannerForAdmin($id)` · `createBanner($input, $admin_id)` → id · `updateBanner($id, $input, $admin_id)` · `setBannerImage($id, $_FILES['banner_image'], $admin_id)` · `deleteBanner($id, $admin_id)`.
Fields: `banner_title`*, `banner_subtitle`, `banner_button_label`, `banner_target_type` (`category`/`product`/`collection`/`url`) + `banner_target_value` (id, `deals`/`new`/`best_sellers`, or address), `banner_sort_order`, `banner_is_active`, `banner_starts_at` / `banner_ends_at` (`<input type="datetime-local">`, **typed in Tanzania time**; saved in UTC — show stored times with a UTC→local conversion).

## Orders — `new OrderManager($db)` · permission `orders.manage` (view: `orders.view`)
| Method | Notes |
|---|---|
| `getOrdersForAdmin($_GET)` | filters `q` (order number, customer name/phone), `order_status`, `order_payment_status`, `page`, `per_page` (`OrderManager::PER_PAGE_OPTIONS`), `sort` (`order_placed_at` / `order_total` / `order_number`) + `direction` (`asc`/`desc`, default `desc`; no sort = newest first) → `['items', 'total', 'page', 'per_page']`; items have customer name/phone, total, `item_count`, region |
| `getOrderForAdmin($id)` | the full order (same shape as the API: items, `events` timeline, `delivery_agent`, address copy) + `customer`, `delivery`, **`allowed_next_statuses`**, **`can_confirm_cash`** · 404 |
| `changeStatus($id, $input, $admin_id)` | `order_status`* (one of `allowed_next_statuses`), `status_note`, `delivery_agent_id` (**required for `dispatched`**). Cancelling returns the stock. The customer is notified. Wrong step → 409 `STATUS_CHANGE_NOT_ALLOWED` |
| `confirmCashCollected($id, $input, $admin_id)` | cash on delivery, after `delivered`: `delivery_cash_collected`* must equal the order total → payment becomes `paid` |
`getManualOrderOptions()`, `createManualCustomer($input, $admin_id)`, `createManualOrder($input, $admin_id)` → order id: an order taken by phone or in person (same order number format, timeline, notification, stock reservation and COD limit as app orders — they come from the shared `Order` helpers).
Payment status `cancelled` = a cancelled/expired order that owed nothing more (shown instead of `cod_pending`).

Constant `OrderManager::ALLOWED_TRANSITIONS` = the allowed steps (confirmed → packed → dispatched → in_transit → delivered; cancel before dispatch).

## Delivery agents — `new DeliveryAgent($db)` · permission `delivery.manage`
`getAllAgentsForAdmin()` (with `active_order_count`) · `getActiveAgents()` (for the dispatch dropdown) · `getAgentForAdmin($id)` · `createAgent($input, $admin_id)` → id · `updateAgent($id, $input, $admin_id)` · `setAgentPhoto($id, $_FILES['delivery_agent_photo'], $admin_id)`.
Fields: `delivery_agent_full_name`*, `delivery_agent_phone`*, `delivery_agent_is_active`.

\* = required
