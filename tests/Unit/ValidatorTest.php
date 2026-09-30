<?php

use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testReturnsOnlyRuledFieldsWithCleanedValues(): void
    {
        $data = Validator::validate(
            [
                'phone'     => '0712 345 678',
                'full_name' => '  Joyce Joseph  ',
                'region_id' => '5',
                'is_default' => 'true',
                'hacker'    => 'ignored',
            ],
            [
                'phone'      => 'required|phone_tz',
                'full_name'  => 'required|string|min:2|max:100',
                'region_id'  => 'required|int|min:1',
                'is_default' => 'nullable|bool',
            ]
        );

        $this->assertSame([
            'phone'      => '+255712345678',
            'full_name'  => 'Joyce Joseph',
            'region_id'  => 5,
            'is_default' => true,
        ], $data);
    }

    public function testReportsEveryInvalidFieldAtOnce(): void
    {
        try {
            Validator::validate(
                ['phone' => '123', 'full_name' => 'J', 'locale' => 'fr'],
                [
                    'phone'     => 'required|phone_tz',
                    'full_name' => 'required|string|min:2',
                    'region_id' => 'required|int',
                    'locale'    => 'nullable|in:sw,en',
                ]
            );
            $this->fail('Expected a validation error');
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->status());
            $this->assertSame('VALIDATION_ERROR', $exception->errorCode());
            $this->assertSame(['phone', 'full_name', 'region_id', 'locale'], array_keys($exception->fields()));
        }
    }

    public function testOptionalFieldsAreSkippedAndEmptyNullableBecomesNull(): void
    {
        $data = Validator::validate(
            ['business_name' => '   '],
            [
                'business_name' => 'nullable|string|max:120',
                'district_id'   => 'nullable|int',
            ]
        );

        $this->assertSame(['business_name' => null], $data);
    }

    public function testIntegerLimits(): void
    {
        $this->expectException(ApiException::class);
        Validator::validate(['quantity' => 0], ['quantity' => 'required|int|min:1|max:100000']);
    }

    public function testRejectsDecimalForInt(): void
    {
        $this->expectException(ApiException::class);
        Validator::validate(['quantity' => '2.5'], ['quantity' => 'required|int']);
    }

    public function testOtpDigitsKeepLeadingZero(): void
    {
        $data = Validator::validate(['code' => '012345'], ['code' => 'required|string|digits:6']);
        $this->assertSame('012345', $data['code']);
    }

    public function testEmail(): void
    {
        $this->assertSame(
            ['email' => 'joyce@example.com'],
            Validator::validate(['email' => 'joyce@example.com'], ['email' => 'nullable|email|max:150'])
        );

        $this->expectException(ApiException::class);
        Validator::validate(['email' => 'not-an-email'], ['email' => 'nullable|email']);
    }

    public function testUnknownRuleIsAProgrammingError(): void
    {
        $this->expectException(LogicException::class);
        Validator::validate(['name' => 'x'], ['name' => 'required|strng']);
    }
}
