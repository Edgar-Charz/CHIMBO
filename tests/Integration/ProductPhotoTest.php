<?php

use PHPUnit\Framework\TestCase;

/** Product photos: safe saving in three sizes, gallery order, main photo, and the product page. */
final class ProductPhotoTest extends TestCase
{
    private Database $db;
    private ProductImage $gallery;
    private int $product_id;
    private array $temporary_files = [];

    protected function setUp(): void
    {
        $this->db         = Database::instance();
        $this->gallery    = new ProductImage($this->db);
        $this->product_id = (int) $this->db->fetchValue("SELECT product_id FROM products WHERE product_name = 'Classic Watch'");
    }

    protected function tearDown(): void
    {
        // Delete through the class, so both the rows and the saved files are removed
        foreach ($this->gallery->getImages($this->product_id) as $image) {
            $this->gallery->deleteImage($this->product_id, $image['product_image_id'], 1);
        }
        array_map('unlink', array_filter($this->temporary_files, 'is_file'));
    }

    public function testPhotoIsSavedInThreeWebpSizes(): void
    {
        $images = $this->gallery->addImageFile($this->product_id, $this->makePhoto(2400, 1800), 1);

        $this->assertCount(1, $images);
        $this->assertTrue($images[0]['product_image_is_primary']); // the first photo becomes the main one

        $large_file = $this->fileOf($images[0]['product_image_large_url']);
        $thumb_file = $this->fileOf($images[0]['product_image_thumb_url']);
        $this->assertSame('image/webp', mime_content_type($large_file));
        $this->assertSame(1600, getimagesize($large_file)[0]); // big photos are reduced
        $this->assertSame(300, getimagesize($thumb_file)[0]);
    }

    public function testFakeImageIsRejected(): void
    {
        $fake_file = sys_get_temp_dir() . '/chimbo_fake.jpg';
        file_put_contents($fake_file, '<?php echo "not an image"; ?>');
        $this->temporary_files[] = $fake_file;

        $this->expectException(ApiException::class);
        $this->gallery->addImageFile($this->product_id, $fake_file, 1);
    }

    public function testTinyImageIsRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->gallery->addImageFile($this->product_id, $this->makePhoto(100, 100), 1);
    }

    public function testReorderAndChooseMainPhoto(): void
    {
        $this->gallery->addImageFile($this->product_id, $this->makePhoto(800, 600), 1);
        $this->gallery->addImageFile($this->product_id, $this->makePhoto(800, 600), 1);
        $images = $this->gallery->addImageFile($this->product_id, $this->makePhoto(800, 600), 1);
        $image_ids = array_column($images, 'product_image_id');

        $images = $this->gallery->reorderImages($this->product_id, array_reverse($image_ids), 1);
        $this->assertSame(array_reverse($image_ids), array_column($images, 'product_image_id'));

        $images = $this->gallery->setMainImage($this->product_id, $image_ids[1], 1);
        $main_images = array_filter($images, fn (array $image) => $image['product_image_is_primary']);
        $this->assertSame([$image_ids[1]], array_column($main_images, 'product_image_id')); // exactly one main photo
    }

    public function testDeletingTheMainPhotoMakesTheNextOneMain(): void
    {
        $this->gallery->addImageFile($this->product_id, $this->makePhoto(800, 600), 1);
        $images = $this->gallery->addImageFile($this->product_id, $this->makePhoto(800, 600), 1);
        $main_file = $this->fileOf($images[0]['product_image_medium_url']);

        $images = $this->gallery->deleteImage($this->product_id, $images[0]['product_image_id'], 1);

        $this->assertCount(1, $images);
        $this->assertTrue($images[0]['product_image_is_primary']);
        $this->assertFileDoesNotExist($main_file); // the files were removed too
    }

    public function testProductPageListsPhotosInOrderAndTheCardShowsTheMainOne(): void
    {
        $this->gallery->addImageFile($this->product_id, $this->makePhoto(800, 600), 1);
        $this->gallery->addImageFile($this->product_id, $this->makePhoto(800, 600), 1);

        $product = (new Product($this->db))->getProductById($this->product_id);

        $this->assertCount(2, $product['images']);
        $this->assertStringEndsWith('-medium.webp', $product['images'][0]);
        $this->assertStringEndsWith('-thumb.webp', $product['product_image_url']);
        $this->assertSame([3 => 18000, 12 => 17000], array_column($product['tiers'], 'tier_unit_price', 'tier_min_quantity'));
    }

    /** Creates a plain JPEG of the given size and returns its path. */
    private function makePhoto(int $width, int $height): string
    {
        $path = sys_get_temp_dir() . '/chimbo_test_' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg(imagecreatetruecolor($width, $height), $path);
        $this->temporary_files[] = $path;
        return $path;
    }

    /** Image URL → file on disk. */
    private function fileOf(string $image_url): string
    {
        return BASE_PATH . '/' . substr($image_url, strlen(url('')));
    }
}
