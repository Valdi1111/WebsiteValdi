<?php

namespace App\Tests\GryphlineBundle\Service;

use App\GryphlineBundle\Service\SkportSignerService;
use PHPUnit\Framework\TestCase;

class SkportSignerServiceTest extends TestCase
{
    private SkportSignerService $signer;

    protected function setUp(): void
    {
        $this->signer = new SkportSignerService();
    }

    public function testGenerateSignV1(): void
    {
        $sign = $this->signer->generateSignV1('1234567890', 'my-cred');
        $expected = md5('timestamp=1234567890&cred=my-cred');
        $this->assertSame($expected, $sign);
    }

    public function testGenerateSignV2ProducesDeterministicMd5Hmac(): void
    {
        $path = '/api/v1/game/endfield/card/detail';
        $timestamp = '1794118333';
        $salt = 'my-salt';

        $sign1 = $this->signer->generateSignV2($path, $timestamp, $salt);
        $sign2 = $this->signer->generateSignV2($path, $timestamp, $salt);

        $this->assertSame($sign1, $sign2);
        $this->assertSame(32, strlen($sign1));
    }

    public function testRequiresV2Sign(): void
    {
        $this->assertTrue($this->signer->requiresV2Sign('/api/v1/game/player/binding'));
        $this->assertTrue($this->signer->requiresV2Sign('/api/v1/game/endfield/card/detail'));
        $this->assertTrue($this->signer->requiresV2Sign('/api/v1/game/attendance'));
        $this->assertTrue($this->signer->requiresV2Sign('/wiki/characters'));
        $this->assertFalse($this->signer->requiresV2Sign('/api/v1/user/profile'));
    }
}
