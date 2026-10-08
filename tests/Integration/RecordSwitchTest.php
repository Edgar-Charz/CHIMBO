<?php

use PHPUnit\Framework\TestCase;

/** The admin's one-click switches (Hide / Show, Activate, Verified): only one column changes, only it is logged. */
final class RecordSwitchTest extends TestCase
{
    private const ADMIN_ID = 1;
    private const VASELINE = 1;

    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        resetCustomerData($this->db);
        $this->db->execute('DELETE FROM audit_logs');
    }

    protected function tearDown(): void
    {
        $this->db->execute('UPDATE products SET product_is_active = 1 WHERE product_id = ' . self::VASELINE);
        $this->db->execute("UPDATE sellers SET seller_status = 'active'");
    }

    public function testHidingAProductChangesOnlyThatColumnAndLogsOnlyIt(): void
    {
        $before = $this->productRow();

        $changed = (new ProductEditor($this->db))->setProductActive(self::VASELINE, false, self::ADMIN_ID);

        $after = $this->productRow();
        $this->assertTrue($changed);
        $this->assertSame(0, (int) $after['product_is_active']);
        $this->assertSame(array_diff_key($before, ['product_is_active' => 1, 'updated_at' => 1]), array_diff_key($after, ['product_is_active' => 1, 'updated_at' => 1]));

        $log = (new AuditLog($this->db))->search(['action' => 'product.updated', 'entity_id' => self::VASELINE])['items'];
        $this->assertSame(['product_is_active' => 0], $log[0]['audit_log_new_values']);
        $this->assertSame(['product_is_active' => 1], $log[0]['audit_log_old_values']);
    }

    public function testSettingTheSameValueAgainWritesNothing(): void
    {
        $product_editor = new ProductEditor($this->db);

        $this->assertFalse($product_editor->setProductActive(self::VASELINE, true, self::ADMIN_ID));   // already visible
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM audit_logs'));
    }

    public function testDeletedOrUnknownRecordsAreNotFound(): void
    {
        $this->expectExceptionObject(ApiException::notFound('This record was not found. It may have been deleted.'));
        (new ProductEditor($this->db))->setProductActive(999999, false, self::ADMIN_ID);
    }

    public function testSellerStatusAcceptsOnlyActiveOrInactive(): void
    {
        $seller_model = new Seller($this->db);
        $seller_id    = (int) $this->db->fetchValue('SELECT seller_id FROM sellers ORDER BY seller_id LIMIT 1');

        $this->assertTrue($seller_model->setSellerStatus($seller_id, 'inactive', self::ADMIN_ID));
        $this->assertSame('inactive', $this->db->fetchValue('SELECT seller_status FROM sellers WHERE seller_id = ' . $seller_id));

        try {
            $seller_model->setSellerStatus($seller_id, 'deleted', self::ADMIN_ID);
            $this->fail('Expected a validation error');
        } catch (ApiException $e) {
            $this->assertArrayHasKey('seller_status', $e->fields());
        }
    }

    private function productRow(): array
    {
        return $this->db->fetchOne('SELECT * FROM products WHERE product_id = ' . self::VASELINE);
    }
}
