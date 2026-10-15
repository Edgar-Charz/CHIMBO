<?php

/**
 * The logo staff upload in the admin (Settings → Logo & pictures, blueprint R-27 §12.9). One picture becomes square
 * PNGs: the logo (512 px) and the browser / phone icons (192, 180, 32 px). The website, admin, PDFs and the app use
 * it; without an upload everyone keeps the built-in CHIMBO mark.
 *
 * Stored as the app_images row with slot "logo" (the 512 px path); the icons sit next to it ("…-192.png" …).
 *
 * How to use it:
 *   $logo = new SiteLogo(Database::instance());
 *   $logo->getUrl('192');                                  // URL of the uploaded 192 px icon, or null
 *   $logo->setLogo($_FILES['logo'], $admin_id);             // admin
 */
class SiteLogo
{
    public const SLOT  = 'logo';
    // size name => pixels: 512 = the logo itself, 192 = Android / browser, 180 = iPhone bookmark, 32 = browser tab
    public const SIZES = ['512' => 512, '192' => 192, '180' => 180, '32' => 32];

    private static ?array $cached_row = null;   // one database read per page
    private static bool $is_cached = false;

    private ImageUploader $uploader;

    public function __construct(private Database $db, ?ImageUploader $uploader = null)
    {
        $this->uploader = $uploader ?? new ImageUploader();
    }

    public static function clearCache(): void
    {
        self::$cached_row = null;
        self::$is_cached  = false;
    }

    /** The uploaded logo's path for one size (e.g. 'media/brand/…-32.png'), or null when there is no upload. */
    public function getPath(string $size_name = '512'): ?string
    {
        $row = $this->currentRow();
        if ($row === null || !isset(self::SIZES[$size_name])) {
            return null;
        }
        return preg_replace('/-512\.png$/', "-{$size_name}.png", $row['app_image_path']);
    }

    /** The same as a full URL (pages, the apps), or null. */
    public function getUrl(string $size_name = '512'): ?string
    {
        $path = $this->getPath($size_name);
        return $path === null ? null : url($path);
    }

    /** When the logo was uploaded (UTC, database format), or null. */
    public function updatedAt(): ?string
    {
        return $this->currentRow()['updated_at'] ?? null;
    }

    /**
     * The logo as a data: URI for PDFs (they can't fetch URLs): the upload, or $fallback_file (a PNG on the server).
     * Null when neither exists.
     */
    public function pdfDataUri(string $fallback_file): ?string
    {
        $path = $this->getPath('192');
        $file = $path !== null && is_file(BASE_PATH . '/' . $path) ? BASE_PATH . '/' . $path : $fallback_file;
        return is_file($file) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($file)) : null;
    }

    /** Uploads a new logo (a browser upload, $_FILES entry). The old files are removed. */
    public function setLogo(?array $uploaded_file, int $admin_id): void
    {
        if ($uploaded_file === null || ($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($uploaded_file['tmp_name'])) {
            throw ApiException::validation(['logo' => 'Choose the logo picture to upload.']);
        }
        $this->saveFrom($uploaded_file['tmp_name'], $admin_id);
    }

    /** The same from a file already on the server (tests, scripts). */
    public function setLogoFromFile(string $file_path, int $admin_id): void
    {
        $this->saveFrom($file_path, $admin_id);
    }

    /** "Use the built-in logo": removes the upload. */
    public function removeLogo(int $admin_id): void
    {
        $old_paths = $this->allPaths();
        if ($old_paths === []) {
            return;
        }
        $this->db->execute('DELETE FROM app_images WHERE app_image_slot = :slot', ['slot' => self::SLOT]);
        $this->uploader->deleteImageFiles($old_paths);
        self::clearCache();
        (new AuditLog($this->db))->record('admin', $admin_id, 'logo.removed', 'app_image', null, ['app_image_slot' => self::SLOT]);
    }

    private function saveFrom(string $source_path, int $admin_id): void
    {
        $paths     = $this->uploader->saveSquareIcons($source_path, 'brand', self::SIZES);
        $old_paths = $this->allPaths();

        $this->db->execute(
            'INSERT INTO app_images (app_image_slot, app_image_path, updated_by_admin_id) VALUES (:slot, :path, :admin_id)
             ON DUPLICATE KEY UPDATE app_image_path = VALUES(app_image_path), updated_by_admin_id = VALUES(updated_by_admin_id)',
            ['slot' => self::SLOT, 'path' => $paths['512'], 'admin_id' => $admin_id]
        );
        $this->uploader->deleteImageFiles($old_paths);
        self::clearCache();

        (new AuditLog($this->db))->record('admin', $admin_id, 'logo.changed', 'app_image', null, ['app_image_slot' => self::SLOT]);
    }

    /** Every file of the current upload (all sizes), or [] when there is none. */
    private function allPaths(): array
    {
        return $this->currentRow() === null ? [] : array_map(fn (string $size): string => (string) $this->getPath($size), array_keys(self::SIZES));
    }

    private function currentRow(): ?array
    {
        if (!self::$is_cached) {
            self::$cached_row = $this->db->fetchOne(
                'SELECT app_image_path, updated_at FROM app_images WHERE app_image_slot = :slot',
                ['slot' => self::SLOT]
            );
            self::$is_cached = true;
        }
        return self::$cached_row;
    }
}
