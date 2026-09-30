<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase
{
    public static function validNumbers(): array
    {
        return [
            'local with 0'        => ['0712345678', '+255712345678'],
            'without 0'           => ['712345678', '+255712345678'],
            'country code'        => ['255712345678', '+255712345678'],
            'plus and spaces'     => ['+255 712 345 678', '+255712345678'],
            'dashes'              => ['+255-654-321-000', '+255654321000'],
            'Airtel/Halotel (6x)' => ['0689 111 222', '+255689111222'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function testNormalizesValidNumbers(string $input, string $expected): void
    {
        $this->assertSame($expected, Phone::normalizeTz($input));
    }

    public static function invalidNumbers(): array
    {
        return [
            'too short'        => ['071234567'],
            'too long'         => ['07123456789'],
            'landline (2x)'    => ['0222123456'],
            'other country'    => ['+254712345678'],
            'letters'          => ['07abc45678'],
            'empty'            => [''],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function testRejectsInvalidNumbers(string $input): void
    {
        $this->assertNull(Phone::normalizeTz($input));
    }

    public function testFormatsForDisplay(): void
    {
        $this->assertSame('+255 712 345 678', Phone::format('+255712345678'));
    }
}
