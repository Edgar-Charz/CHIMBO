<?php

/**
 * Everything the Home tab ("Nyumbani") shows, in one answer, so the app loads Home with one request.
 */
class Home
{
    private const RAIL_SIZE = 10; // products per horizontal rail

    public function __construct(private Database $db)
    {
    }

    /** $user_id: the logged-in customer (adds their recently ordered products), or null for a guest. */
    public function getHome(?int $user_id = null): array
    {
        $product_model = new Product($this->db);

        return [
            'banners'          => (new Banner($this->db))->getActiveBanners(),
            'top_categories'   => (new Category($this->db))->getCategoryTree(),
            'best_sellers'     => $product_model->getCollection('best_sellers', self::RAIL_SIZE),
            'deals'            => $product_model->getCollection('deals', self::RAIL_SIZE),
            'offers'           => $product_model->getCollection('offers', self::RAIL_SIZE),   // "Ofa za muda", ending soonest first
            'new_arrivals'     => $product_model->getCollection('new', self::RAIL_SIZE),
            'recently_ordered' => $user_id === null ? [] : (new Order($this->db))->getRecentlyOrderedProducts($user_id, self::RAIL_SIZE),
        ];
    }
}
