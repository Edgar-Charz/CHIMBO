<?php

/**
 * Saves uploaded photos safely, in three WebP sizes. Used for products, profile photos and banners.
 *
 * Safety: the real file type is checked (not the file name), the size and pixel dimensions are
 * limited, and the image is re-drawn by GD — this removes hidden data (location, scripts) and
 * guarantees the saved file is a clean image. Files get random names inside media/.
 *
 * How to use it:
 *   $uploader = new ImageUploader();
 *   $paths = $uploader->saveUploadedFile($_FILES['product_image'], 'products/12');
 *   // → ['thumb' => 'media/products/12/9f3c…-thumb.webp', 'medium' => '…', 'large' => '…']
 */
class ImageUploader
{
    private const MAX_FILE_BYTES = 8 * 1024 * 1024;   // 8 MB
    private const MAX_PIXELS     = 6000;              // longest side of the original photo
    private const MIN_PIXELS     = 200;               // too small to look good on a phone
    private const WEBP_QUALITY   = 80;

    // Longest side of each saved size, in pixels
    private const SIZES = ['thumb' => 300, 'medium' => 800, 'large' => 1600];

    private const LOADERS = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
    ];

    /** For a browser upload (a $_FILES entry). Throws a 422 error with a clear message if it is not acceptable. */
    public function saveUploadedFile(array $uploaded_file, string $folder): array
    {
        if (($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($uploaded_file['tmp_name'])) {
            throw $this->imageError('Picha haikupakiwa vizuri. Tafadhali jaribu tena.');
        }
        return $this->saveImageFile($uploaded_file['tmp_name'], $folder);
    }

    /**
     * Checks one image file and saves it in the three sizes under media/{$folder}/.
     * Returns the paths (relative to the project folder) to store in the database.
     */
    public function saveImageFile(string $source_path, string $folder): array
    {
        $image = $this->loadCheckedImage($source_path);

        $target_folder = 'media/' . trim($folder, '/');
        if (!is_dir(BASE_PATH . '/' . $target_folder)) {
            mkdir(BASE_PATH . '/' . $target_folder, 0755, true);
        }

        $file_name = bin2hex(random_bytes(12)); // random, so names can't be guessed or clash
        $paths     = [];

        foreach (self::SIZES as $size_name => $longest_side) {
            $resized_image = $this->resize($image, $longest_side);
            $path = "{$target_folder}/{$file_name}-{$size_name}.webp";
            imagewebp($resized_image, BASE_PATH . '/' . $path, self::WEBP_QUALITY);
            $paths[$size_name] = $path;
        }

        return $paths;
    }

    /** Deletes the saved files of one image (all sizes). Missing files are ignored. */
    public function deleteImageFiles(array $paths): void
    {
        foreach ($paths as $path) {
            // Only files inside media/ may ever be deleted
            if (str_starts_with($path, 'media/') && is_file(BASE_PATH . '/' . $path)) {
                unlink(BASE_PATH . '/' . $path);
            }
        }
    }

    /** Checks size, real type and dimensions, then opens the image (turned upright if the phone saved it sideways). */
    private function loadCheckedImage(string $source_path): GdImage
    {
        if (!is_file($source_path) || filesize($source_path) > self::MAX_FILE_BYTES) {
            throw $this->imageError('Picha ni kubwa mno. Ukubwa wa juu ni MB 8.');
        }

        // The real type from the file's content — a renamed script cannot pass as an image
        $mime_type = (new finfo(FILEINFO_MIME_TYPE))->file($source_path);
        if (!isset(self::LOADERS[$mime_type])) {
            throw $this->imageError('Aina hii ya picha haikubaliki. Tumia JPG, PNG au WEBP.');
        }

        $dimensions = @getimagesize($source_path);
        if ($dimensions === false) {
            throw $this->imageError('Faili hili si picha sahihi.');
        }
        [$width, $height] = $dimensions;
        if (max($width, $height) > self::MAX_PIXELS || min($width, $height) < self::MIN_PIXELS) {
            throw $this->imageError('Picha inapaswa kuwa kati ya pikseli ' . self::MIN_PIXELS . ' na ' . self::MAX_PIXELS . '.');
        }

        $image = @(self::LOADERS[$mime_type])($source_path);
        if ($image === false) {
            throw $this->imageError('Faili hili si picha sahihi.');
        }

        return $mime_type === 'image/jpeg' ? $this->turnUpright($image, $source_path) : $image;
    }

    /** Makes a copy whose longest side is at most $longest_side (small images are never enlarged). */
    private function resize(GdImage $image, int $longest_side): GdImage
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $scale  = min(1, $longest_side / max($width, $height));

        $new_width  = max(1, (int) round($width * $scale));
        $new_height = max(1, (int) round($height * $scale));

        $resized_image = imagecreatetruecolor($new_width, $new_height);
        imagealphablending($resized_image, false); // keep transparent backgrounds (PNG)
        imagesavealpha($resized_image, true);
        imagecopyresampled($resized_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

        return $resized_image;
    }

    /** Phone photos often store "rotate me" in EXIF instead of rotating the pixels; apply it. */
    private function turnUpright(GdImage $image, string $source_path): GdImage
    {
        $exif = @exif_read_data($source_path);

        return match ($exif['Orientation'] ?? 1) {
            3       => imagerotate($image, 180, 0),
            6       => imagerotate($image, -90, 0),
            8       => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    private function imageError(string $message): ApiException
    {
        return new ApiException(422, 'INVALID_IMAGE', $message);
    }
}
