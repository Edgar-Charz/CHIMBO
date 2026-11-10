<?php

/**
 * Promotional banners on Home (e.g. "BEI ZA JUMLA â€” Nunua Sasa").
 * A banner can open a category, a product, a collection (deals | new | best_sellers | offers) or a web address.
 *
 * Shop:  getActiveBanners()
 * Staff: getAllBannersForAdmin(), getBannerForAdmin(), createBanner(), updateBanner(), setBannerImage(), deleteBanner()
 *        Start/end times are typed in Tanzania time and saved in UTC.
 */
class Banner
{
    private const COLLECTIONS = ['deals', 'new', 'best_sellers', 'offers'];

    private const RULES = [
        'banner_title'        => 'required|string|min:2|max:100',
        'banner_title_en'     => 'required|string|min:2|max:100',
        'banner_subtitle'     => 'nullable|string|max:160',
        'banner_subtitle_en'  => 'nullable|string|max:160',
        'banner_button_label'    => 'nullable|string|max:40',
        'banner_button_label_en' => 'nullable|string|max:40',
        'banner_target_type'  => 'nullable|in:category,product,collection,url',
        'banner_target_value' => 'nullable|string|max:255',
        'banner_sort_order'   => 'nullable|int|min:0|max:1000',
        'banner_is_active'    => 'nullable|bool',
        'banner_starts_at'    => 'nullable|datetime',
        'banner_ends_at'      => 'nullable|datetime',
    ];

    public function __construct(private Database $db)
    {
    }

    // ---------------------------------------------------------------- Staff (admin pages)

    public function getAllBannersForAdmin(): array
    {
        return $this->db->fetchAll('SELECT * FROM banners ORDER BY banner_sort_order, banner_id');
    }

    public function getBannerForAdmin(int $banner_id): array
    {
        $banner = $this->db->fetchOne('SELECT * FROM banners WHERE banner_id = :banner_id', ['banner_id' => $banner_id]);
        if ($banner === null) {
            throw ApiException::notFound('Banner not found.');
        }
        return $banner;
    }

    /** Returns the new banner_id. */
    public function createBanner(array $input, int $admin_id): int
    {
        $values = $this->checkedValues($input);

        $banner_id = $this->db->insert(
            'INSERT INTO banners (
                banner_title, banner_title_en, banner_subtitle, banner_subtitle_en,
                banner_button_label, banner_button_label_en, banner_target_type, banner_target_value,
                banner_sort_order, banner_is_active, banner_starts_at, banner_ends_at
             ) VALUES (
                :banner_title, :banner_title_en, :banner_subtitle, :banner_subtitle_en,
                :banner_button_label, :banner_button_label_en, :banner_target_type, :banner_target_value,
                :banner_sort_order, :banner_is_active, :banner_starts_at, :banner_ends_at
             )',
            $values
        );

        (new AuditLog($this->db))->record('admin', $admin_id, 'banner.created', 'banner', $banner_id, null, $values);
        return $banner_id;
    }

    public function updateBanner(int $banner_id, array $input, int $admin_id): void
    {
        $old_banner = $this->getBannerForAdmin($banner_id);
        $values     = $this->checkedValues($input);

        $this->db->execute(
            'UPDATE banners
             SET banner_title = :banner_title, banner_title_en = :banner_title_en,
                 banner_subtitle = :banner_subtitle, banner_subtitle_en = :banner_subtitle_en,
                 banner_button_label = :banner_button_label, banner_button_label_en = :banner_button_label_en,
                 banner_target_type = :banner_target_type, banner_target_value = :banner_target_value,
                 banner_sort_order = :banner_sort_order, banner_is_active = :banner_is_active,
                 banner_starts_at = :banner_starts_at, banner_ends_at = :banner_ends_at
             WHERE banner_id = :banner_id',
            $values + ['banner_id' => $banner_id]
        );

        (new AuditLog($this->db))->record('admin', $admin_id, 'banner.updated', 'banner', $banner_id, $old_banner, $values);
    }

    /** Uploads the banner picture (replaces the old one). */
    public function setBannerImage(int $banner_id, array $uploaded_file, int $admin_id): void
    {
        $old_banner = $this->getBannerForAdmin($banner_id);
        $uploader   = new ImageUploader();
        $paths      = $uploader->saveUploadedFile($uploaded_file, "banners/{$banner_id}");

        // A banner uses the medium size only; the other sizes are removed straight away
        $uploader->deleteImageFiles([$paths['thumb'], $paths['large']]);
        $this->db->execute(
            'UPDATE banners SET banner_image_path = :banner_image_path WHERE banner_id = :banner_id',
            ['banner_image_path' => $paths['medium'], 'banner_id' => $banner_id]
        );
        if ($old_banner['banner_image_path']) {
            $uploader->deleteImageFiles([$old_banner['banner_image_path']]);
        }

        (new AuditLog($this->db))->record('admin', $admin_id, 'banner.image_changed', 'banner', $banner_id);
    }

    public function deleteBanner(int $banner_id, int $admin_id): void
    {
        $old_banner = $this->getBannerForAdmin($banner_id);

        $this->db->execute('DELETE FROM banners WHERE banner_id = :banner_id', ['banner_id' => $banner_id]);
        if ($old_banner['banner_image_path']) {
            (new ImageUploader())->deleteImageFiles([$old_banner['banner_image_path']]);
        }

        (new AuditLog($this->db))->record('admin', $admin_id, 'banner.deleted', 'banner', $banner_id, $old_banner);
    }

    /** Validates the form and returns the values to save (times converted to UTC). */
    private function checkedValues(array $input): array
    {
        $data = Validator::validate($input, self::RULES);

        $errors = [];
        if (!empty($data['banner_subtitle']) && empty($data['banner_subtitle_en'])) {
            $errors['banner_subtitle_en'] = 'Add the English subtitle so the banner is translated in both languages.';
        }
        if (!empty($data['banner_button_label']) && empty($data['banner_button_label_en'])) {
            $errors['banner_button_label_en'] = 'Add the English button text so the banner is translated in both languages.';
        }
        $target_type  = $data['banner_target_type'] ?? null;
        $target_value = $data['banner_target_value'] ?? null;
        if ($target_type !== null) {
            if (!is_string($target_value) || trim($target_value) === '') {
                $errors['banner_target_value'] = 'Choose or enter where the banner should open.';
            } elseif ($target_type === 'collection' && !in_array($target_value, self::COLLECTIONS, true)) {
                $errors['banner_target_value'] = 'Choose deals, new products, best sellers or offers.';
            } elseif (in_array($target_type, ['category', 'product'], true)) {
                if (!ctype_digit($target_value) || (int) $target_value < 1) {
                    $errors['banner_target_value'] = 'Choose a valid category or product.';
                } else {
                    $table          = $target_type === 'category' ? 'categories' : 'products';
                    $column         = $target_type === 'category' ? 'category_id' : 'product_id';
                    $deleted_filter = $target_type === 'product' ? ' AND deleted_at IS NULL' : '';
                    $exists         = $this->db->fetchValue(
                        "SELECT {$column} FROM {$table} WHERE {$column} = :target_id{$deleted_filter}",
                        ['target_id' => (int) $target_value]
                    );
                    if ($exists === null) {
                        $errors['banner_target_value'] = 'That category or product no longer exists.';
                    }
                }
            } elseif ($target_type === 'url') {
                $scheme = strtolower((string) parse_url($target_value, PHP_URL_SCHEME));
                if (filter_var($target_value, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
                    $errors['banner_target_value'] = 'Enter a complete web address starting with https:// or http://.';
                }
            }
        }
        if (isset($data['banner_starts_at'], $data['banner_ends_at']) && $data['banner_ends_at'] <= $data['banner_starts_at']) {
            $errors['banner_ends_at'] = 'The end must be after the start.';
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }

        return [
            'banner_title'        => $data['banner_title'],
            'banner_title_en'     => $data['banner_title_en'],
            'banner_subtitle'     => $data['banner_subtitle'] ?? null,
            'banner_subtitle_en'  => $data['banner_subtitle_en'] ?? null,
            'banner_button_label'    => $data['banner_button_label'] ?? null,
            'banner_button_label_en' => $data['banner_button_label_en'] ?? null,
            'banner_target_type'  => $target_type,
            'banner_target_value' => $target_type === null ? null : $target_value,
            'banner_sort_order'   => $data['banner_sort_order'] ?? 0,
            'banner_is_active'    => (int) ($data['banner_is_active'] ?? false),
            'banner_starts_at'    => localTimeToUtc($data['banner_starts_at'] ?? null),
            'banner_ends_at'      => localTimeToUtc($data['banner_ends_at'] ?? null),
        ];
    }

    // ---------------------------------------------------------------- Shop (API)

    /** Active banners whose dates (if set) include today. */
    public function getActiveBanners(string $language = 'sw'): array
    {
        $rows = $this->db->fetchAll(
            'SELECT banner_id, banner_title, banner_title_en, banner_subtitle, banner_subtitle_en,
                    banner_image_path, banner_button_label, banner_button_label_en,
                    banner_target_type, banner_target_value
             FROM banners
             WHERE banner_is_active = 1
               AND (banner_starts_at IS NULL OR banner_starts_at <= UTC_TIMESTAMP())
               AND (banner_ends_at IS NULL OR banner_ends_at > UTC_TIMESTAMP())
             ORDER BY banner_sort_order, banner_id'
        );

        $use_english = strtolower(substr($language, 0, 2)) === 'en';

        return array_map(fn (array $row) => [
            'banner_id'           => (int) $row['banner_id'],
            'banner_title'        => $use_english && !empty($row['banner_title_en']) ? $row['banner_title_en'] : $row['banner_title'],
            'banner_subtitle'     => $use_english && !empty($row['banner_subtitle_en']) ? $row['banner_subtitle_en'] : $row['banner_subtitle'],
            'banner_image_url'    => $row['banner_image_path'] ? url($row['banner_image_path']) : null,
            'banner_button_label' => $use_english && !empty($row['banner_button_label_en']) ? $row['banner_button_label_en'] : $row['banner_button_label'],
            'banner_target_type'  => $row['banner_target_type'],
            'banner_target_value' => $row['banner_target_value'],
        ], $rows);
    }


    /** "Hide / Show": changes only banner_is_active (the schedule is left untouched). Returns false when nothing changed. */
    public function setBannerActive(int $banner_id, bool $is_active, int $admin_id): bool
    {
        return (new RecordSwitch($this->db))->set('banners', 'banner_id', $banner_id, 'banner_is_active', (int) $is_active, $admin_id, 'banner');
    }
}
