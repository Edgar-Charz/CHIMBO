<?php

use PHPUnit\Framework\TestCase;

/** Customer request photos remain private to the account that sent them. */
final class ProductRequestTest extends TestCase
{
    private Database $db;
    private ProductRequest $product_request;
    private int $user_id;
    private int $request_id;
    private string $image_path;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        $this->user_id = (new User($this->db))->findOrCreateUserIdByPhone('+255712000333');
        $this->product_request = new ProductRequest($this->db);

        $private_directory = BASE_PATH . '/media/product-requests';
        if (!is_dir($private_directory)) {
            mkdir($private_directory, 0755, true);
        }
        $file_name = 'test-' . bin2hex(random_bytes(8)) . '-large.webp';
        $this->image_path = 'media/product-requests/' . $file_name;
        file_put_contents(BASE_PATH . '/' . $this->image_path, 'private test image');
        $this->request_id = $this->db->insert(
            'INSERT INTO product_requests (user_id, product_request_description, product_request_image_path)
             VALUES (:user_id, :description, :image_path)',
            ['user_id' => $this->user_id, 'description' => 'Gold necklace', 'image_path' => $this->image_path]
        );
    }

    protected function tearDown(): void
    {
        $full_path = BASE_PATH . '/' . $this->image_path;
        if (is_file($full_path)) {
            unlink($full_path);
        }
    }

    public function testCustomerCanViewTheirOwnReferencePhoto(): void
    {
        $this->assertSame(
            realpath(BASE_PATH . '/' . $this->image_path),
            $this->product_request->getRequestImagePathForUser($this->user_id, $this->request_id)
        );
        $request = $this->product_request->getRequestsForUser($this->user_id)[0];
        $this->assertSame(
            url('api/v1/product-requests/' . $this->request_id . '/image'),
            $request['product_request_image_url']
        );
    }

    public function testAnotherCustomerCannotViewTheReferencePhoto(): void
    {
        $other_user_id = (new User($this->db))->findOrCreateUserIdByPhone('+255712000444');

        $this->expectException(ApiException::class);
        $this->product_request->getRequestImagePathForUser($other_user_id, $this->request_id);
    }
}
