<?php

/**
 * Pictures of the app's fixed screens (onboarding, login / registration, order success) that staff can replace
 * in the admin (Settings → App pictures). The app keeps its built-in picture for any slot without an upload,
 * and also whenever it is offline (e.g. the very first start).
 *
 * How to use it:
 *   $app_image_model = new AppImage(Database::instance());
 *   $app_image_model->getImagesForApps();                                  // public: [{app_image_slot, app_image_url, updated_at}]
 *   $app_image_model->setImage('onboarding_products', $_FILES['app_image'], $admin_id);   // admin
 */
class AppImage
{
    // slot => [where it shows, recommended size, saved size]. Slots match the app's assets/images files.
    public const SLOTS = [
        'onboarding_products'  => ['Onboarding slide 1 "Bidhaa za Biashara Yako"', '1080 × 1350 (portrait 4:5), products centred', 'large'],
        'onboarding_wholesale' => ['Onboarding slide 2 "Bei za Jumla Zinazokulipa"', '1080 × 1350 (portrait 4:5), products in the TOP half (a price card covers the bottom)', 'large'],
        'onboarding_delivery'  => ['Onboarding slide 3 "Agiza kwa Urahisi. Pokea Haraka."', '1080 × 1350 (portrait 4:5), shop / box / van centred', 'large'],
        'auth_welcome'         => ['Login: phone number screen "Karibu CHIMBO"', '1600 × 1000 (landscape 16:10), centred', 'large'],
        'auth_verify'          => ['Login: SMS code and PIN screens', '1600 × 1000 (landscape 16:10), centred', 'large'],
        'auth_business'        => ['Registration: business details "Tuambie Kuhusu Biashara Yako"', '1600 × 1000 (landscape 16:10), centred', 'large'],
        'order_success'        => ['"Oda Imepokelewa!" after ordering', '1600 × 1000 (landscape 16:10), centred', 'large'],
    ];

    private ImageUploader $uploader;

    public function __construct(private Database $db, ?ImageUploader $uploader = null)
    {
        $this->uploader = $uploader ?? new ImageUploader();
    }

    /** Public, for the apps and website: [{app_image_slot, app_image_url, updated_at}] — only slots with an uploaded picture. */
    public function getImagesForApps(): array
    {
        $images = [];
        foreach ($this->db->fetchAll('SELECT app_image_slot, app_image_path, updated_at FROM app_images ORDER BY app_image_slot') as $row) {
            if (isset(self::SLOTS[$row['app_image_slot']])) {
                $images[] = [
                    'app_image_slot' => $row['app_image_slot'],
                    'app_image_url'  => url($row['app_image_path']),   // a new upload always gets a new URL
                    'updated_at'     => isoDate($row['updated_at']),
                ];
            }
        }
        return $images;
    }

    /** The admin list: every slot with its description, size advice and current picture path (null = the app's own). */
    public function listForAdmin(): array
    {
        $saved = array_column($this->db->fetchAll('SELECT * FROM app_images'), null, 'app_image_slot');

        $slots = [];
        foreach (self::SLOTS as $slot => [$label, $size_advice]) {
            $slots[] = [
                'app_image_slot'   => $slot,
                'label'            => $label,
                'size_advice'      => $size_advice,
                'app_image_path'   => $saved[$slot]['app_image_path'] ?? null,   // show with url()
                'updated_at'       => $saved[$slot]['updated_at'] ?? null,
            ];
        }
        return $slots;
    }

    /** Uploads a new picture for the slot (a browser upload, $_FILES entry). The old file is removed. */
    public function setImage(string $slot, ?array $uploaded_file, int $admin_id): void
    {
        $this->checkSlot($slot);
        if ($uploaded_file === null) {
            throw ApiException::validation(['app_image' => 'Choose a picture to upload.']);
        }
        $this->savePaths($slot, $this->uploader->saveUploadedFile($uploaded_file, 'app'), $admin_id);
    }

    /** The same from a file already on the server (tests, scripts). */
    public function setImageFromFile(string $slot, string $file_path, int $admin_id): void
    {
        $this->checkSlot($slot);
        $this->savePaths($slot, $this->uploader->saveImageFile($file_path, 'app'), $admin_id);
    }

    /** "Use the app's own picture": removes the upload. */
    public function removeImage(string $slot, int $admin_id): void
    {
        $this->checkSlot($slot);
        $old_path = $this->currentPath($slot);
        if ($old_path === null) {
            return;
        }

        $this->db->execute('DELETE FROM app_images WHERE app_image_slot = :slot', ['slot' => $slot]);
        $this->uploader->deleteImageFiles([$old_path]);
        (new AuditLog($this->db))->record('admin', $admin_id, 'app_image.removed', 'app_image', null, ['app_image_slot' => $slot]);
    }

    /** Keeps the one size the slot needs, saves it and removes the previous file. */
    private function savePaths(string $slot, array $paths, int $admin_id): void
    {
        $kept_size = self::SLOTS[$slot][2];
        $this->uploader->deleteImageFiles(array_values(array_diff_key($paths, [$kept_size => true])));
        $old_path = $this->currentPath($slot);

        $this->db->execute(
            'INSERT INTO app_images (app_image_slot, app_image_path, updated_by_admin_id) VALUES (:slot, :path, :admin_id)
             ON DUPLICATE KEY UPDATE app_image_path = VALUES(app_image_path), updated_by_admin_id = VALUES(updated_by_admin_id)',
            ['slot' => $slot, 'path' => $paths[$kept_size], 'admin_id' => $admin_id]
        );
        if ($old_path !== null) {
            $this->uploader->deleteImageFiles([$old_path]);
        }

        (new AuditLog($this->db))->record('admin', $admin_id, 'app_image.changed', 'app_image', null, ['app_image_slot' => $slot]);
    }

    private function currentPath(string $slot): ?string
    {
        $path = $this->db->fetchValue('SELECT app_image_path FROM app_images WHERE app_image_slot = :slot', ['slot' => $slot]);
        return $path === null ? null : (string) $path;
    }

    private function checkSlot(string $slot): void
    {
        if (!isset(self::SLOTS[$slot])) {
            throw ApiException::notFound('Unknown picture slot.');
        }
    }
}
