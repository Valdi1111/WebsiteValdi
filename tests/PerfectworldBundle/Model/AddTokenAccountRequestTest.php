<?php

namespace App\Tests\PerfectworldBundle\Model;

use App\PerfectworldBundle\Model\Auth\AddTokenAccountRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AddTokenAccountRequestTest extends TestCase
{
    public function testWebsdkJsonIsParsed(): void
    {
        $request = (new AddTokenAccountRequest())->setWebsdk(' {"uid":2000000001,"token":"0f0e0d0c-0b0a-4908-8706-050403020100"} ');

        $this->assertSame(['uid' => '2000000001', 'token' => '0f0e0d0c-0b0a-4908-8706-050403020100'], $request->getCredentials());
    }

    public function testSeparateUidAndTokenAreAccepted(): void
    {
        $request = (new AddTokenAccountRequest())->setUid(2000000001)->setToken('0f0e0d0c-0b0a-4908-8706-050403020100');

        $this->assertSame('2000000001', $request->getCredentials()['uid'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidWebsdkProvider(): iterable
    {
        yield 'not json' => ['uid=1;token=2'];
        yield 'missing token' => ['{"uid":2000000001}'];
        yield 'non numeric uid' => ['{"uid":"abc","token":"0f0e0d0c-0b0a-4908-8706-050403020100"}'];
        yield 'token with spaces' => ['{"uid":2000000001,"token":"bad token value"}'];
    }

    #[DataProvider('invalidWebsdkProvider')]
    public function testInvalidValuesAreRejected(string $websdk): void
    {
        $this->assertNull((new AddTokenAccountRequest())->setWebsdk($websdk)->getCredentials());
    }
}
