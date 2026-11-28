<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Model\Chat\ChatAction;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatActionStyle;
use App\CoreBundle\Model\Chat\ChatImage;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\TextReplyRenderer;
use PHPUnit\Framework\TestCase;

class TextReplyRendererTest extends TestCase
{
    public function testEverythingOfAReply(): void
    {
        $status = new UniversalEmbed()
            ->setTitle('Genshin Impact - Status')
            ->setDescription("Traveler · AR 60\nEurope")
            ->addField('Stamina', '120/200', '🌙')
            ->addField('Dailies', '4/4')
            ->setFooterText('Valdi')
            ->setTimestamp(new \DateTimeImmutable('2026-11-27T10:00:00+01:00'));
        $reply = new ChatReply(
            'Your profiles',
            [$status, new UniversalEmbed()->setTitle('Second')],
            [
                [new ChatAction('Refresh', new ChatActionRef('refresh', ['status', '1']), '🔄'), new ChatAction('Next', new ChatActionRef('page', ['2']), style: ChatActionStyle::Primary)],
                [new ChatAction('Broken', new ChatActionRef('bad:name'))],
            ],
            page: 1,
            pageCount: 3,
        );

        $this->assertSame(<<<'TEXT'
            Your profiles

            Genshin Impact - Status
            Traveler · AR 60
            Europe
            🌙 Stamina: 120/200
            Dailies: 4/4
            Valdi · 2026-11-27T10:00:00+01:00

            Second

            [🔄 Refresh] → refresh:status:1  [Next] → page:2
            [Broken] → (invalid: Chat action handler "bad:name": not empty and without ":".)

            Page 1/3
            TEXT, new TextReplyRenderer(new ChatActionCodec())->render($reply));
    }

    public function testTheImageIsALineAfterTheEmbedsAndBeforeTheButtons(): void
    {
        // 150 KB and 900 bytes: the size is rounded down
        $image = new ChatImage('pulls-nelly.png', 'image/png', str_repeat("\0", 150 * 1024 + 900), 2400, 1880, 'Pulls of Nelly');
        $reply = new ChatReply(
            'Your pulls',
            [new UniversalEmbed()->setTitle('Limited')],
            [[new ChatAction('Refresh', new ChatActionRef('refresh', ['pulls', '1']), '🔄')]],
            page: 2,
            pageCount: 3,
            image: $image,
        );

        $this->assertSame(<<<'TEXT'
            Your pulls

            Limited

            [image: pulls-nelly.png, image/png, 2400x1880, 150 KB]

            [🔄 Refresh] → refresh:pulls:1

            Page 2/3
            TEXT, new TextReplyRenderer(new ChatActionCodec())->render($reply));
    }

    public function testAnImageAlone(): void
    {
        $image = new ChatImage('card.webp', 'image/webp', 'tiny', 2400, 6600);

        $this->assertSame('[image: card.webp, image/webp, 2400x6600, 0 KB]', new TextReplyRenderer(new ChatActionCodec())->render(ChatReply::image($image)));
    }

    public function testTextOnly(): void
    {
        $this->assertSame('⚠️ ' . ChatReply::ERROR, new TextReplyRenderer(new ChatActionCodec())->render(ChatReply::error()));
    }
}
