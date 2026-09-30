<?php

use PHPUnit\Framework\TestCase;

final class AdminPermissionsTest extends TestCase
{
    public function testSuperAdminCanDoEverything(): void
    {
        $this->assertTrue(Admin::can(['admin_role' => 'super_admin'], 'anything.at_all'));
    }

    public function testRolesOnlyGetTheirOwnPermissions(): void
    {
        $catalog_admin = ['admin_role' => 'catalog'];

        $this->assertTrue(Admin::can($catalog_admin, 'products.manage'));
        $this->assertFalse(Admin::can($catalog_admin, 'payments.manage'));
    }

    public function testUnknownRoleGetsNothing(): void
    {
        $this->assertFalse(Admin::can(['admin_role' => 'intruder'], 'dashboard.view'));
    }
}
