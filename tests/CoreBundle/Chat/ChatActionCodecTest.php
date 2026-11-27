<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Exception\InvalidChatActionRefException;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class ChatActionCodecTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $codec = new ChatActionCodec();

        foreach ([new ChatActionRef('refresh'), new ChatActionRef('refresh', ['status', 'hoyoverse', '42']), new ChatActionRef('page', ['', '2'])] as $ref) {
            $data = $codec->encode($ref);
            $this->assertEquals($ref, $codec->decode($data), $data);
        }
        $this->assertSame('refresh:status:hoyoverse:42', $codec->encode(new ChatActionRef('refresh', ['status', 'hoyoverse', '42'])));
    }

    public function testTheLimitIsInBytes(): void
    {
        $codec = new ChatActionCodec();

        // "refresh:" + 56 = 64 bytes
        $this->assertSame(64, strlen($codec->encode(new ChatActionRef('refresh', [str_repeat('a', 56)]))));

        // 28 "é" are 28 characters but 56 bytes: 37 characters, 65 bytes
        $this->expectException(InvalidChatActionRefException::class);
        $this->expectExceptionMessage('takes 65 bytes, at most 64');
        $codec->encode(new ChatActionRef('refresh', [str_repeat('é', 28) . 'a']));
    }

    public function testMultibyteArgumentOverTheLimit(): void
    {
        $this->expectException(InvalidChatActionRefException::class);

        new ChatActionCodec()->encode(new ChatActionRef('refresh', [str_repeat('é', 40)]));
    }

    #[TestWith(['', []])]
    #[TestWith(['re:fresh', []])]
    #[TestWith(['refresh', ['a:b']])]
    #[TestWith(['refresh', ['ok', '12:30']])]
    public function testSeparatorOrEmptyHandlerIsRefused(string $handler, array $args): void
    {
        $this->expectException(InvalidChatActionRefException::class);

        new ChatActionCodec()->encode(new ChatActionRef($handler, $args));
    }

    #[TestWith([''])]
    #[TestWith([':status'])]
    #[TestWith([':'])]
    public function testUnknownFormatCannotBeDecoded(string $data): void
    {
        $this->expectException(InvalidChatActionRefException::class);

        new ChatActionCodec()->decode($data);
    }

    public function testDataLongerThanAnyEncodedRefCannotBeDecoded(): void
    {
        $this->expectException(InvalidChatActionRefException::class);

        new ChatActionCodec()->decode('refresh:' . str_repeat('a', 57));
    }
}
