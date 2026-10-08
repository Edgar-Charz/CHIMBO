<?php

use PHPUnit\Framework\TestCase;

/** "Pakua Risiti" (receipt PDF) and the profile photo. */
final class ReceiptAndAvatarTest extends TestCase
{
    private Database $db;
    private User $user_model;
    private int $user_id;
    private array $temporary_files = [];

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        $this->user_model = new User($this->db);
        $this->user_id    = $this->user_model->findOrCreateUserIdByPhone('+255712000111');
        $this->user_model->completeProfile($this->user_id, [
            'user_full_name' => 'Joyce Joseph',
            'region_id'      => $this->darEsSalaamId(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->user_model->removeAvatar($this->user_id);
        array_map('unlink', array_filter($this->temporary_files, 'is_file'));
        $this->db->execute('UPDATE products SET product_stock_quantity = 500, product_sold_count = 820 WHERE product_id = 1');
    }

    public function testCustomerGetsAPdfReceiptForTheirOrder(): void
    {
        $order_id = $this->placeOrder();

        $receipt = (new Receipt($this->db))->createReceiptForCustomer($this->user_id, $order_id);

        $this->assertStringStartsWith('%PDF', $receipt['pdf']);
        $this->assertMatchesRegularExpression('/^Risiti-CHB\d{6}\.pdf$/', $receipt['file_name']);
    }

    public function testNobodyElseCanDownloadTheReceipt(): void
    {
        $order_id      = $this->placeOrder();
        $other_user_id = $this->user_model->findOrCreateUserIdByPhone('+255754000222');

        $this->expectException(ApiException::class);
        (new Receipt($this->db))->createReceiptForCustomer($other_user_id, $order_id);
    }

    public function testStaffCanPrintAnyReceipt(): void
    {
        $order_id = $this->placeOrder();

        $this->assertStringStartsWith('%PDF', (new Receipt($this->db))->createReceiptForAdmin($order_id)['pdf']);
    }

    public function testNewPhotoReplacesTheOldOneAndItsFile(): void
    {
        $first  = $this->user_model->setAvatarFromFile($this->user_id, $this->makePhoto());
        $second = $this->user_model->setAvatarFromFile($this->user_id, $this->makePhoto());

        $this->assertStringEndsWith('-thumb.webp', $second['user_avatar_url']);
        $this->assertFileDoesNotExist($this->fileOf($first['user_avatar_url']));
        $this->assertFileExists($this->fileOf($second['user_avatar_url']));
    }

    public function testRemovingThePhotoDeletesTheFile(): void
    {
        $profile = $this->user_model->setAvatarFromFile($this->user_id, $this->makePhoto());

        $this->assertNull($this->user_model->removeAvatar($this->user_id)['user_avatar_url']);
        $this->assertFileDoesNotExist($this->fileOf($profile['user_avatar_url']));
    }

    public function testDeletingTheAccountDeletesThePhoto(): void
    {
        $profile = $this->user_model->setAvatarFromFile($this->user_id, $this->makePhoto());

        $this->user_model->deleteAccount($this->user_id, ['confirm' => true]);

        $this->assertFileDoesNotExist($this->fileOf($profile['user_avatar_url']));
    }

    public function testMissingPhotoIsAClearError(): void
    {
        $this->expectExceptionObject(ApiException::validation(['avatar' => 'Chagua picha.']));
        $this->user_model->setAvatar($this->user_id, null);
    }

    private function placeOrder(): int
    {
        $address_id = (new Address($this->db))->createAddress($this->user_id, [
            'address_recipient_name' => 'Joyce Joseph',
            'address_phone'          => '0712345678',
            'region_id'              => $this->darEsSalaamId(),
            'address_street'         => 'Kariakoo, Lumumba Street',
        ])['address_id'];
        (new Cart($this->db))->addItem($this->user_id, ['product_id' => 1, 'quantity' => 8]);
        $total = (new Checkout($this->db))->preview($this->user_id, ['delivery_method_id' => 1, 'address_id' => $address_id])['grand_total'];

        return (new Order($this->db))->placeOrder($this->user_id, [
            'address_id' => $address_id, 'delivery_method_id' => 1, 'payment_method' => 'cod', 'expected_total' => $total,
        ], 'app', null)['order_id'];
    }

    private function makePhoto(): string
    {
        $path = sys_get_temp_dir() . '/chimbo_avatar_' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg(imagecreatetruecolor(600, 600), $path);
        $this->temporary_files[] = $path;
        return $path;
    }

    private function fileOf(string $image_url): string
    {
        return BASE_PATH . '/' . substr($image_url, strlen(url('')));
    }

    private function darEsSalaamId(): int
    {
        return (int) $this->db->fetchValue("SELECT region_id FROM regions WHERE region_name = 'Dar es Salaam'");
    }
}
