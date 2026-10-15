<?php

use PHPUnit\Framework\TestCase;

require_once BASE_PATH . '/admin/includes/admin_helpers.php';

/** Dashboard cards: big shilling amounts are shortened so they always fit ("260.5M TZS"). */
final class AdminShortAmountTest extends TestCase
{
    public function testAmountsBelowAMillionStayInFull(): void
    {
        $this->assertSame('0', adminShortAmount(0));
        $this->assertSame('524,100', adminShortAmount(524100));
        $this->assertSame('999,999', adminShortAmount(999999));
    }

    public function testMillionsAndBillionsGetOneDecimal(): void
    {
        $this->assertSame('1M', adminShortAmount(1000000));
        $this->assertSame('260.5M', adminShortAmount(260498100));
        $this->assertSame('1.3B', adminShortAmount(1250000000));
    }

    public function testRoundingUpToAThousandMillionsSaysBillion(): void
    {
        $this->assertSame('1B', adminShortAmount(999999999));   // not "1,000M"
    }
}
