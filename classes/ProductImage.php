<?php

/**
 * The photo gallery of a product: add, list, reorder, choose the main photo, delete.
 * The main photo is the one shown on product cards; the first photo added becomes main automatically.
 *
 * How to use it (admin product page):
 *   $product_images = new ProductImage(Database::instance());
 *   $product_images->addImage($product_id, $_FILES['product_image'], $admin_id);
 *   $product_images->setMainImage($product_id, $product_image_id, $admin_id);
 *   $product_images->reorderImages($product_id, [7, 5, 6], $admin_id);   // ids in the new order
 *   $product_images->deleteImage($product_id, $product_image_id, $admin_id);
 */
class ProductImage
{
    public const MAX_IMAGES_PER_PRODUCT = 8;

    private ImageUploader $uploader;

    public function __construct(private Database $db, ?ImageUploader $uploader = null)
    {
        $this->uploader = $uploader ?? new ImageUploader();
    }

    /** The photos of a product in display order, with the URL of each size. */
    public function getImages(int $product_id): array
    {
        $rows = $this->db->fetchAll(
            'SELECT product_image_id, product_image_thumb_path, product_image_medium_path, product_image_large_path,
                    product_image_is_primary
             FROM product_images
             WHERE product_id = :product_id
             ORDER BY product_image_sort_order, product_image_id',
            ['product_id' => $product_id]
        );

        return array_map(fn (array $row) => [
            'product_image_id'         => (int) $row['product_image_id'],
            'product_image_thumb_url'  => url($row['product_image_thumb_path']),
            'product_image_medium_url' => url($row['product_image_medium_path']),
            'product_image_large_url'  => url($row['product_image_large_path']),
            'product_image_is_primary' => (bool) $row['product_image_is_primary'],
        ], $rows);
    }

    /** Adds a browser-uploaded photo ($_FILES entry). Returns the product's updated gallery. */
    public function addImage(int $product_id, array $uploaded_file, int $admin_id): array
    {
        return $this->storeImage($product_id, fn (string $folder) => $this->uploader->saveUploadedFile($uploaded_file, $folder), $admin_id);
    }

    /** Adds a photo from a file already on the server (used by seeds and tests). */
    public function addImageFile(int $product_id, string $file_path, int $admin_id): array
    {
        return $this->storeImage($product_id, fn (string $folder) => $this->uploader->saveImageFile($file_path, $folder), $admin_id);
    }

    /** Makes this photo the main one (shown on product cards). */
    public function setMainImage(int $product_id, int $product_image_id, int $admin_id): array
    {
        $this->requireImageOfProduct($product_id, $product_image_id);

        $this->db->transaction(function () use ($product_id, $product_image_id) {
            $this->db->execute('UPDATE product_images SET product_image_is_primary = 0 WHERE product_id = :product_id', ['product_id' => $product_id]);
            $this->db->execute('UPDATE product_images SET product_image_is_primary = 1 WHERE product_image_id = :product_image_id', ['product_image_id' => $product_image_id]);
        });

        (new AuditLog($this->db))->record('admin', $admin_id, 'product.image_main_changed', 'product', $product_id, null, ['product_image_id' => $product_image_id]);
        return $this->getImages($product_id);
    }

    /** Saves a new order. $ordered_image_ids must list every photo of the product exactly once. */
    public function reorderImages(int $product_id, array $ordered_image_ids, int $admin_id): array
    {
        $ordered_image_ids = array_map('intval', $ordered_image_ids);
        $current_image_ids = array_column($this->getImages($product_id), 'product_image_id');

        $same_images = count($ordered_image_ids) === count($current_image_ids)
            && array_diff($ordered_image_ids, $current_image_ids) === []
            && count(array_unique($ordered_image_ids)) === count($ordered_image_ids);
        if (!$same_images) {
            throw ApiException::validation(['product_image_ids' => 'Orodha ya picha haiendani na picha za bidhaa hii.']);
        }

        $this->db->transaction(function () use ($ordered_image_ids) {
            foreach ($ordered_image_ids as $position => $product_image_id) {
                $this->db->execute(
                    'UPDATE product_images SET product_image_sort_order = :sort_order WHERE product_image_id = :product_image_id',
                    ['sort_order' => $position + 1, 'product_image_id' => $product_image_id]
                );
            }
        });

        (new AuditLog($this->db))->record('admin', $admin_id, 'product.images_reordered', 'product', $product_id, null, ['order' => $ordered_image_ids]);
        return $this->getImages($product_id);
    }

    /** Deletes a photo (database row and files). If it was the main photo, the next one becomes main. */
    public function deleteImage(int $product_id, int $product_image_id, int $admin_id): array
    {
        $image = $this->requireImageOfProduct($product_id, $product_image_id);

        $this->db->transaction(function () use ($product_id, $product_image_id, $image) {
            $this->db->execute('DELETE FROM product_images WHERE product_image_id = :product_image_id', ['product_image_id' => $product_image_id]);
            if ($image['product_image_is_primary']) {
                $this->makeFirstImageMain($product_id);
            }
        });

        // Files are removed only after the database change succeeded
        $this->uploader->deleteImageFiles([
            $image['product_image_thumb_path'], $image['product_image_medium_path'], $image['product_image_large_path'],
        ]);

        (new AuditLog($this->db))->record('admin', $admin_id, 'product.image_deleted', 'product', $product_id, ['product_image_id' => $product_image_id]);
        return $this->getImages($product_id);
    }

    /** Shared by addImage() and addImageFile(): checks the limit, saves the files, adds the row. */
    private function storeImage(int $product_id, callable $save_files, int $admin_id): array
    {
        $image_count = (int) $this->db->fetchValue('SELECT COUNT(*) FROM product_images WHERE product_id = :product_id', ['product_id' => $product_id]);
        if ($image_count >= self::MAX_IMAGES_PER_PRODUCT) {
            throw ApiException::validation(['product_image' => 'Bidhaa inaweza kuwa na picha ' . self::MAX_IMAGES_PER_PRODUCT . ' tu.']);
        }
        if (!$this->db->fetchValue('SELECT 1 FROM products WHERE product_id = :product_id', ['product_id' => $product_id])) {
            throw ApiException::notFound('Bidhaa haikupatikana.');
        }

        $paths = $save_files("products/{$product_id}");

        try {
            $product_image_id = $this->db->insert(
                'INSERT INTO product_images (
                    product_id, product_image_thumb_path, product_image_medium_path, product_image_large_path,
                    product_image_sort_order, product_image_is_primary
                 ) VALUES (:product_id, :thumb_path, :medium_path, :large_path, :sort_order, :is_primary)',
                [
                    'product_id'  => $product_id,
                    'thumb_path'  => $paths['thumb'],
                    'medium_path' => $paths['medium'],
                    'large_path'  => $paths['large'],
                    'sort_order'  => $image_count + 1,
                    'is_primary'  => (int) ($image_count === 0), // the first photo becomes the main one
                ]
            );
        } catch (Throwable $e) {
            $this->uploader->deleteImageFiles($paths); // don't leave orphan files behind
            throw $e;
        }

        (new AuditLog($this->db))->record('admin', $admin_id, 'product.image_added', 'product', $product_id, null, ['product_image_id' => $product_image_id]);
        return $this->getImages($product_id);
    }

    /** The image row, or 404 if it does not belong to this product. */
    private function requireImageOfProduct(int $product_id, int $product_image_id): array
    {
        $image = $this->db->fetchOne(
            'SELECT product_image_thumb_path, product_image_medium_path, product_image_large_path, product_image_is_primary
             FROM product_images WHERE product_image_id = :product_image_id AND product_id = :product_id',
            ['product_image_id' => $product_image_id, 'product_id' => $product_id]
        );
        if ($image === null) {
            throw ApiException::notFound('Picha haikupatikana.');
        }
        return $image;
    }

    private function makeFirstImageMain(int $product_id): void
    {
        $this->db->execute(
            'UPDATE product_images SET product_image_is_primary = 1
             WHERE product_id = :product_id
             ORDER BY product_image_sort_order, product_image_id
             LIMIT 1',
            ['product_id' => $product_id]
        );
    }
}
