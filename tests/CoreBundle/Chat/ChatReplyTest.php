<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Model\Chat\ChatAction;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatImage;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use PHPUnit\Framework\TestCase;

/**
 * ChatReply as the senders read it: what counts as an answer (an image does, buttons alone do not), and an answer with
 * an image.
 */
class ChatReplyTest extends TestCase
{
    private static function image(): ChatImage
    {
        return new ChatImage('pulls.png', 'image/png', "\x89PNG\r\n\x1a\n", 2400, 1880, 'Pulls of Nelly');
    }

    public function testAnImageWithItsCaption(): void
    {
        $image = self::image();

        $reply = ChatReply::image($image, 'Your pulls');

        $this->assertSame($image, $reply->image);
        $this->assertSame('Your pulls', $reply->text);
        // An answer with an image has no buttons, nor pages
        $this->assertSame([], $reply->embeds);
        $this->assertSame([], $reply->actionRows);
        $this->assertSame([1, 1], [$reply->page, $reply->pageCount]);
        $this->assertFalse($reply->isEmpty());
    }

    public function testAnImageAloneIsAnAnswer(): void
    {
        $reply = ChatReply::image(self::image());

        $this->assertNull($reply->text);
        $this->assertFalse($reply->isEmpty());
        $this->assertFalse(ChatReply::image(self::image(), '  ')->isEmpty());
    }

    public function testWhatIsEmpty(): void
    {
        $this->assertTrue(new ChatReply()->isEmpty());
        $this->assertTrue(ChatReply::text(" \n ")->isEmpty());
        // Buttons and pages alone are not an answer
        $this->assertTrue(new ChatReply(null, [], [[new ChatAction('Next', new ChatActionRef('page', ['2']))]], 1, 2)->isEmpty());

        $this->assertFalse(ChatReply::text('Hi')->isEmpty());
        $this->assertFalse(ChatReply::embeds([new UniversalEmbed()->setTitle('Stamina')])->isEmpty());
        $this->assertFalse(ChatReply::error()->isEmpty());
    }

    public function testTheSizeOfAnImageIsInBytes(): void
    {
        $this->assertSame(8, self::image()->size());
        $this->assertSame(4, new ChatImage('é.png', 'image/png', 'éé', 1, 1)->size());
        $this->assertSame(0, new ChatImage('empty.png', 'image/png', '', 1, 1)->size());
    }
}
