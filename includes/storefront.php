<?php

/**
 * Small helpers for the storefront pages (links, assets, the logged-in customer).
 * Pages only READ through the classes here; every change goes through the API from JavaScript.
 */

const SHOP_NAME = 'CHIMBO';
const SHOP_TAGLINE = 'Bidhaa bora • Bei nafuu • Biashara imara';
const SHOP_THEME_COLOR = '#0E3B2A'; // browser bar colour on phones; the same green as --chimbo-green in theme.css

const CATALOG_PAGE_SIZE = 20;
const CATALOG_SORT_OPTIONS = [
    'popular'    => 'Maarufu',
    'newest'     => 'Mpya zaidi',
    'price_asc'  => 'Bei: ndogo kwanza',
    'price_desc' => 'Bei: kubwa kwanza',
];
const PRODUCT_BADGE_LABELS = ['deal' => 'Ofa', 'bestseller' => 'Inauzwa sana', 'new' => 'Mpya'];
const PRODUCT_SUGGESTION_COUNT = 10;

// Order words — the same as the mobile app, so customers see one language everywhere
const ORDER_STATUS_LABELS = [
    'pending_payment' => 'Inasubiri malipo',
    'confirmed'       => 'Imethibitishwa',
    'packed'          => 'Imepakiwa',
    'dispatched'      => 'Imetumwa',
    'in_transit'      => 'Inasafirishwa',
    'delivered'       => 'Imewasili',
    'cancelled'       => 'Imeghairiwa',
    'expired'         => 'Muda umeisha',
];
const ORDER_STATUS_MESSAGES = [
    'pending_payment' => 'Kamilisha malipo ili tuanze kuandaa oda yako.',
    'confirmed'       => 'Tunaandaa oda yako.',
    'packed'          => 'Oda yako iko tayari kutumwa.',
    'dispatched'      => 'Oda yako imekabidhiwa wakala.',
    'in_transit'      => 'Oda yako iko njiani.',
    'delivered'       => 'Asante kwa kununua CHIMBO!',
    'cancelled'       => 'Oda hii imeghairiwa.',
    'expired'         => 'Muda wa malipo umeisha.',
];
const ORDER_STATUS_TONES = [ // colour of the status pill
    'pending_payment' => 'warning',
    'delivered'       => 'success',
    'cancelled'       => 'danger',
    'expired'         => 'muted',
];
const ORDER_TRACKING_STEPS = ['confirmed', 'packed', 'dispatched', 'in_transit', 'delivered'];
const ORDER_STOPPED_STATUSES = ['cancelled', 'expired'];
const ORDER_REORDER_STATUSES = ['delivered', 'cancelled']; // "Agiza Tena" only once an order is finished
const ORDER_CARD_PHOTOS = 4; // product photos shown on an order in "Oda Zangu"
const PAYMENT_STATUS_LABELS = [
    'unpaid'      => 'Haijalipwa',
    'pending'     => 'Inakaguliwa',
    'paid'        => 'Imelipwa',
    'cod_pending' => 'Utalipa ukipokea',
    'refunded'    => 'Imerudishwa',
    'failed'      => 'Imeshindikana',
    'cancelled'   => 'Hakuna cha kulipa',
];

/** Link to a file in assets/ with its change time, so browsers load a new version after an edit. */
function asset(string $path): string
{
    $file = BASE_PATH . '/assets/' . $path;
    $version = is_file($file) ? filemtime($file) : 0;

    return url('assets/' . $path) . '?v=' . $version;
}

/**
 * Pretty product link: /p/{id}-{slug}. The id finds the product; the slug is for people and Google
 * (without it, /p/{id} still works and the product page adds it).
 */
function productUrl(int $product_id, string $product_slug = ''): string
{
    return url($product_slug === '' ? "p/{$product_id}" : "p/{$product_id}-{$product_slug}");
}

/** Pretty category link: /c/{id}-{slug}, optionally opened on one chip (sub-category). */
function categoryUrl(int $category_id, string $category_slug = '', ?int $chip_id = null): string
{
    $link = url($category_slug === '' ? "c/{$category_id}" : "c/{$category_id}-{$category_slug}");

    return $chip_id === null ? $link : $link . '?chip=' . $chip_id;
}

/** Search results for one collection: deals, new or best_sellers. */
function collectionUrl(string $collection): string
{
    return url('search.php?collection=' . rawurlencode($collection));
}

/** Where a Home banner leads. Outside links are allowed only over http(s), never "javascript:" and the like. */
function bannerUrl(array $banner): string
{
    $target_value = (string) $banner['banner_target_value'];

    return match ($banner['banner_target_type']) {
        'category'   => categoryUrl((int) $target_value),
        'product'    => productUrl((int) $target_value),
        'collection' => collectionUrl($target_value),
        'url'        => in_array(parse_url($target_value, PHP_URL_SCHEME), ['http', 'https'], true) ? $target_value : url(''),
        default      => collectionUrl('deals'),
    };
}

/** 5500 → "TZS 5,500" (the same format as CHIMBO.formatTzs in JavaScript). */
function formatTzs(int $amount): string
{
    return 'TZS ' . number_format($amount);
}

/**
 * Price tiers ready to show: each tier runs up to the next tier's minimum − 1, the last one is "60+".
 * Returns [['min_quantity' => 6, 'label' => '6–23 pcs', 'unit_price' => 5000, 'price_before_offer' => null,
 * 'savings_percent' => 9, 'is_best' => false], …]
 * (savings compared with the first tier's price).
 */
function tierRows(array $tiers): array
{
    $normal_price = $tiers[0]['tier_unit_price'];
    $rows = [];
    foreach ($tiers as $index => $tier) {
        $next_tier = $tiers[$index + 1] ?? null;
        $rows[] = [
            'min_quantity'       => $tier['tier_min_quantity'],
            'label'              => $next_tier === null
                ? "{$tier['tier_min_quantity']}+ pcs"
                : "{$tier['tier_min_quantity']}–" . ($next_tier['tier_min_quantity'] - 1) . ' pcs',
            'unit_price'         => $tier['tier_unit_price'],
            'price_before_offer' => $tier['tier_price_before_offer'] ?? null, // set only while a time-limited offer runs
            'savings_percent'    => (int) round((1 - $tier['tier_unit_price'] / $normal_price) * 100),
            'is_best'            => $next_tier === null,
        ];
    }

    return $rows;
}

/** 1 → "1 pc", 8 → "8 pcs" (the same words as CHIMBO.formatPieces and the app). */
function formatPieces(int $count): string
{
    return number_format($count) . ($count === 1 ? ' pc' : ' pcs');
}

/**
 * The card badge: "Ofa −15%" during a time-limited offer, "−8%" for a deal with an old price, otherwise the
 * badge name (null = no badge). Same rules as badgeText() in product_card.js.
 */
function productBadgeText(array $product): ?string
{
    if ($product['product_badge'] === 'offer' && $product['product_offer'] !== null) {
        return 'Ofa −' . $product['product_offer']['product_offer_percent'] . '%';
    }
    $compare_at_price = $product['product_compare_at_price'];
    if ($product['product_badge'] === 'deal' && $compare_at_price > $product['product_price']) {
        return '−' . round((1 - $product['product_price'] / $compare_at_price) * 100) . '%';
    }

    return PRODUCT_BADGE_LABELS[$product['product_badge']] ?? null;
}

/**
 * "Unaweza pia kupenda" on the product page: the related products (same sub-category) first, topped up with
 * popular products from the same top category and then the shop's best sellers — never the product itself.
 */
function productSuggestions(array $product, array $related_products, ?array $parent_category): array
{
    $suggestions = array_column($related_products, null, 'product_id');
    $top_up_queries = array_filter([
        $parent_category === null ? null : ['category_id' => $parent_category['category_id']],
        ['collection' => 'best_sellers'],
    ]);

    foreach ($top_up_queries as $query) {
        if (count($suggestions) > PRODUCT_SUGGESTION_COUNT) {
            break;
        }
        $results = (new Product(Database::instance()))->getProducts($query + ['per_page' => PRODUCT_SUGGESTION_COUNT + 1]);
        $suggestions += array_column($results['items'], null, 'product_id');
    }
    unset($suggestions[$product['product_id']]);

    return array_slice(array_values($suggestions), 0, PRODUCT_SUGGESTION_COUNT);
}

/**
 * "Fuatilia Oda": the timeline steps with their state — done (reached, with its time), current, todo,
 * or stopped (the order was cancelled/expired: only the steps it reached, then how it ended).
 */
function orderTimeline(array $order): array
{
    $reached_at = array_column($order['events'], 'created_at', 'order_status'); // status → time it was reached
    $status = $order['order_status'];
    $is_stopped = in_array($status, ORDER_STOPPED_STATUSES, true);

    $steps = array_key_exists('pending_payment', $reached_at) ? ['pending_payment', ...ORDER_TRACKING_STEPS] : ORDER_TRACKING_STEPS;
    if ($is_stopped) {
        $steps = [...array_values(array_filter($steps, fn (string $step): bool => array_key_exists($step, $reached_at))), $status];
    }

    return array_map(fn (string $step): array => [
        'status'  => $step,
        'label'   => ORDER_STATUS_LABELS[$step],
        'time'    => isset($reached_at[$step]) ? localDateTime($reached_at[$step], 'd/m/Y, H:i') : null,
        'state'   => match (true) {
            $step === $status && $is_stopped => 'stopped',
            $step === $status                => 'current',
            isset($reached_at[$step])        => 'done',
            default                          => 'todo',
        },
    ], $steps);
}

/** Link to the receipt PDF (the API sends it; the website's login cookie is enough to open it). */
function receiptUrl(int $order_id): string
{
    return url("api/v1/orders/{$order_id}/receipt");
}

/** The small "Ofa −15%" tag on order lines (empty when the line had no offer). Same as CHIMBO.offerTag(). */
function offerTag(int $offer_percent): string
{
    return $offer_percent > 0 ? '<span class="offer-tag">' . e("Ofa −{$offer_percent}%") . '</span>' : '';
}

/** A whole number from the address bar (?id=5), or null when missing or not a number ≥ $minimum. */
function requestInt(string $name, int $minimum = 1): ?int
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);

    return is_int($value) ? $value : null;
}

/** Text from the address bar (?q=…), trimmed; '' when missing. */
function requestText(string $name): string
{
    $value = $_GET[$name] ?? '';

    return is_string($value) ? trim($value) : '';
}

/** Sort and filters for a product list, read from the address bar (?sort=&min_price=&max_price=&max_moq=). */
function catalogFilters(string $default_sort = 'popular'): array
{
    $sort = requestText('sort');

    return [
        'sort'      => isset(CATALOG_SORT_OPTIONS[$sort]) ? $sort : $default_sort,
        'min_price' => requestInt('min_price', 0),
        'max_price' => requestInt('max_price', 0),
        'max_moq'   => requestInt('max_moq'),
    ];
}

/**
 * Everything a product list page gives catalog.js: the fixed part of the query (a category, a search or a
 * collection), the filters, the first page of products and the empty-state text.
 * A filter the server refuses (e.g. search text that is too long) comes back as 'error' (Kiswahili).
 */
function catalogState(array $base_query, array $filters, array $empty_state): array
{
    $state = [
        'base_query'   => $base_query,
        'filters'      => $filters,
        'default_sort' => $filters['sort'],
        'per_page'     => CATALOG_PAGE_SIZE,
        'empty'        => $empty_state,
        'products'     => [],
        'total'        => 0,
        'error'        => null,
    ];

    try {
        $results = (new Product(Database::instance()))->getProducts($base_query + $filters + ['per_page' => CATALOG_PAGE_SIZE]);
        $state['products'] = $results['items'];
        $state['total'] = $results['total'];
    } catch (ApiException $e) {
        $state['error'] = array_values($e->fields())[0] ?? $e->getMessage();
    }

    return $state;
}

/**
 * A "return to" path after login that is safe to redirect to: only a path inside this shop
 * (never another website, e.g. ?return=//evil.example). Anything else → the home page.
 */
function safeReturnPath(string $return_path): string
{
    $shop_path = (string) parse_url(url(''), PHP_URL_PATH); // e.g. "/chimbo/"
    $is_inside_shop = str_starts_with($return_path, $shop_path)
        && !str_contains($return_path, '\\')
        && !preg_match('/[\r\n]/', $return_path);

    return $is_inside_shop ? $return_path : $shop_path;
}

/** True once a customer has a PIN and has finished the business details step. */
function isCustomerReady(?array $customer): bool
{
    return $customer !== null && $customer['user_has_pin'] && $customer['is_profile_complete'];
}

/**
 * Pages only for logged-in customers call this first: guests — and customers who still have to create a PIN
 * or finish the business details — go to login and come back here afterwards.
 */
function requireCustomer(): array
{
    $customer = currentCustomer();
    if (!isCustomerReady($customer)) {
        redirect(loginUrl((string) ($_SERVER['REQUEST_URI'] ?? '')));
    }

    return $customer;
}

/** How long ago, in Kiswahili: "Sasa hivi", "Dakika 5 zilizopita", "Saa 3 zilizopita", "Jana, 14:20", or the date. */
function timeAgo(string $utc_time): string
{
    $seconds = time() - (new DateTimeImmutable($utc_time))->getTimestamp();

    return match (true) {
        $seconds < 60          => 'Sasa hivi',
        $seconds < 3600        => 'Dakika ' . intdiv($seconds, 60) . ' zilizopita',
        $seconds < 86400       => 'Saa ' . intdiv($seconds, 3600) . ' zilizopita',
        $seconds < 2 * 86400   => 'Jana, ' . localDateTime($utc_time, 'H:i'),
        default                => localDateTime($utc_time, 'd/m/Y'),
    };
}

/** A calendar date in Kiswahili: '2026-10-02' → "Ijumaa, 2 Oktoba". */
function swahiliDate(string $date): string
{
    $day_names = ['Jumapili', 'Jumatatu', 'Jumanne', 'Jumatano', 'Alhamisi', 'Ijumaa', 'Jumamosi'];
    $month_names = ['Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni', 'Julai', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Desemba'];
    $day = new DateTimeImmutable($date);

    return $day_names[(int) $day->format('w')] . ', ' . $day->format('j') . ' ' . $month_names[(int) $day->format('n') - 1];
}

/** Login page that returns the customer to $return_path afterwards. */
function loginUrl(string $return_path = ''): string
{
    return $return_path === '' ? url('login.php') : url('login.php') . '?return=' . rawurlencode($return_path);
}

/** The logged-in customer's profile (same shape as GET /me), or null for guests. Loaded once per request. */
function currentCustomer(): ?array
{
    static $customer = false;

    if ($customer === false) {
        $user_id = CustomerSession::currentUserId();
        $user = new User(Database::instance());
        $customer = ($user_id !== null && $user->isActiveUser($user_id)) ? $user->getProfile($user_id) : null;
    }

    return $customer;
}

/** First name for greetings ("Habari, Joyce"), or null when unknown. */
function customerFirstName(): ?string
{
    $full_name = currentCustomer()['user_full_name'] ?? null;

    return $full_name === null ? null : explode(' ', trim($full_name))[0];
}

/** Active categories with their chips (same shape as GET /categories). Loaded once per request. */
function shopCategories(): array
{
    static $categories = null;

    return $categories ??= (new Category(Database::instance()))->getCategoryTree();
}

/** Fills in the page settings every layout partial reads. */
function pageSettings(array $page): array
{
    return $page + [
        'title'        => SHOP_NAME,
        'description'  => 'CHIMBO — soko la jumla la vipodozi na urembo kwa wenye maduka Tanzania. Bei za jumla, lipa ukipokea.',
        'image'        => url('assets/img/logo-mark.webp'),
        'nav'          => '',     // active bottom-nav tab: home, explore, cart, orders, account
        'scripts'      => [],     // page scripts in assets/js/, loaded after the shared ones
        'body_class'   => '',
        'search_query' => '',     // shown in the header search box (search page)
        'is_focused'   => false,  // login and checkout: a calm header (logo only), no menus, footer or bottom navigation
    ];
}

/** Settings the JavaScript needs, printed once as JSON in the page head. */
function storefrontConfig(): array
{
    return [
        'base_url'     => url(''),
        'api_url'      => url('api/v1'),
        'login_url'    => url('login.php'),
        'is_logged_in' => currentCustomer() !== null,
        'server_time'  => gmdate(DATE_ATOM), // offer countdowns use the server's clock, not the computer's
    ];
}
