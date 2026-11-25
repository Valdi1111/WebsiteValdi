<?php

namespace App\Tests\HoyoverseBundle\Presenter;

use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\GameNotes\GameNotesStateMetric;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Service\DurationFormatter;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Notes\GameNotesExpeditions;
use App\HoyoverseBundle\Model\Notes\GameNotesRealm;
use App\HoyoverseBundle\Model\Notes\ZenlessZoneZeroVhsSale;
use App\HoyoverseBundle\Presenter\ExpeditionsPresenter;
use App\HoyoverseBundle\Presenter\RealmCurrencyPresenter;
use App\HoyoverseBundle\Presenter\ShopStatusPresenter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The presenters show the data as it is: no threshold, no "all done" check.
 */
class HoyoversePresentersTest extends TestCase
{
    private static function profile(string $gameName): HoyoverseGameProfile
    {
        return new HoyoverseGameProfile()
            ->setGameUid('100000001')
            ->setGameName($gameName)
            ->setNickname('Traveler')
            ->setLevel(60)
            ->setRealmCurrencyThreshold(2000);
    }

    private static function embedFactory(): GameProfileEmbedFactory
    {
        return new GameProfileEmbedFactory(new MockClock());
    }

    /**
     * @return list<array{0: ?string, 1: ?string, 2: ?string, 3: bool}> the data fields, player header excluded
     */
    private static function dataFields(UniversalEmbed $embed): array
    {
        $fields = $embed->getFields();
        self::assertSame(['Player', 'Region', 'Rank'], array_map(static fn (EmbedField $field) => $field->getName(), array_slice($fields, 0, 3)));

        return array_map(
            static fn (EmbedField $field) => [$field->getName(), $field->getValue(), $field->getIcon(), $field->isInline()],
            array_slice($fields, 3)
        );
    }

    public function testRealmCurrencyBelowTheThreshold(): void
    {
        $realm = new GameNotesRealm()->setCurrentCoin(300)->setMaxCoin(2400)->setCoinRecoverTime(86400 + 3600);

        $embed = new RealmCurrencyPresenter(self::embedFactory(), new DurationFormatter())->present(self::profile('Genshin Impact'), $realm, 'Realm Currency');

        $this->assertSame('Genshin Impact - Realm Currency', $embed->getTitle());
        $this->assertSame('https://hoyolab.com/favicon.ico', $embed->getFooterIconUrl());
        $this->assertSame([
            ['Realm Currency', '300/2400', '💰', true],
            ['Recovery Time', '1 day, 1 hr, 0 sec', '🕒', true],
        ], self::dataFields($embed));
    }

    public function testRealmCurrencyWithoutRecoveryTime(): void
    {
        $realm = new GameNotesRealm()->setCurrentCoin(2400)->setMaxCoin(2400);

        $embed = new RealmCurrencyPresenter(self::embedFactory(), new DurationFormatter())->present(self::profile('Genshin Impact'), $realm, 'Realm Currency');

        $this->assertSame([['Realm Currency', '2400/2400', '💰', true]], self::dataFields($embed));
    }

    private static function expeditionsPresenter(): ExpeditionsPresenter
    {
        return new ExpeditionsPresenter(self::embedFactory(), new DurationFormatter());
    }

    public function testExpeditionsStillOut(): void
    {
        $expeditions = new GameNotesExpeditions()
            ->addExpedition('avatar-1.png', 'finished', 0)
            ->addExpedition('avatar-2.png', 'ongoing', 3600)
            ->addExpedition('avatar-3.png', 'ongoing', 5 * 3600 + 125);

        $embed = self::expeditionsPresenter()->present(self::profile('Honkai: Star Rail'), $expeditions, 'Expeditions', 'Ongoing');

        $this->assertSame('Honkai: Star Rail - Expeditions', $embed->getTitle());
        $this->assertSame('Ongoing', $embed->getDescription());
        $this->assertSame([
            ['Expeditions', '1/3 completed', '🧭', true],
            ['Completion Time', '5 hr, 2 min, 5 sec', '🕒', true],
        ], self::dataFields($embed));
    }

    public function testTimesCountFromTheInstantsOfTheFetch(): void
    {
        // Notes fetched 4 minutes before the embed clock
        $now = new \DateTimeImmutable('2026-11-22 10:00:00', new \DateTimeZone('UTC'));
        $fetchedAt = $now->modify('-240 seconds');
        $factory = new GameProfileEmbedFactory(new MockClock($now));
        $realm = new GameNotesRealm()->setCurrentCoin(1000)->setMaxCoin(2400)->setCoinRecoverTime(3600)->setFullAt($fetchedAt->modify('+3600 seconds'));
        $expeditions = new GameNotesExpeditions()
            ->addExpedition('avatar-1.png', 'ongoing', 3600, $fetchedAt->modify('+3600 seconds'))
            ->addExpedition('avatar-2.png', 'ongoing', 7200, $fetchedAt->modify('+7200 seconds'));

        $realmEmbed = new RealmCurrencyPresenter($factory, new DurationFormatter())->present(self::profile('Genshin Impact'), $realm, 'Realm Currency');
        $expeditionsEmbed = new ExpeditionsPresenter($factory, new DurationFormatter())->present(self::profile('Genshin Impact'), $expeditions, 'Expeditions');

        $this->assertSame(['Recovery Time', '56 min, 0 sec', '🕒', true], self::dataFields($realmEmbed)[1]);
        $this->assertSame(['Completion Time', '1 hr, 56 min, 0 sec', '🕒', true], self::dataFields($expeditionsEmbed)[1]);
    }

    public function testExpeditionsAllBack(): void
    {
        $expeditions = new GameNotesExpeditions()
            ->addExpedition('avatar-1.png', 'finished', 0)
            ->addExpedition('avatar-2.png', 'finished', 0);

        $embed = self::expeditionsPresenter()->present(self::profile('Genshin Impact'), $expeditions, 'Expeditions');

        $this->assertSame([['Expeditions', '2/2 completed', '🧭', true]], self::dataFields($embed));
    }

    public function testNoExpeditionsDispatched(): void
    {
        $embed = self::expeditionsPresenter()->present(self::profile('Genshin Impact'), new GameNotesExpeditions(), 'Expeditions');

        $this->assertSame([['Expeditions', 'None dispatched', '🧭', true]], self::dataFields($embed));
    }

    public function testExpeditionWithoutRemainingTime(): void
    {
        $expeditions = new GameNotesExpeditions()
            ->addExpedition('avatar-1.png', 'ongoing', 3600)
            ->addExpedition('avatar-2.png', 'ongoing', null);

        $embed = self::expeditionsPresenter()->present(self::profile('Genshin Impact'), $expeditions, 'Expeditions');

        // The last return is unknown: no completion time rather than the one of the other expedition
        $this->assertSame([['Expeditions', '0/2 completed', '🧭', true]], self::dataFields($embed));
    }

    public function testShopStatusOpen(): void
    {
        $shopStatus = new GameNotesStateMetric()
            ->setName('Shop Status')
            ->setCurrentValue(ZenlessZoneZeroVhsSale::DOING)
            ->setTargetValue(ZenlessZoneZeroVhsSale::DONE);

        $embed = new ShopStatusPresenter(self::embedFactory())->present(self::profile('Zenless Zone Zero'), $shopStatus, 'Shop Status');

        $this->assertSame('Zenless Zone Zero - Shop Status', $embed->getTitle());
        $this->assertNull($embed->getDescription());
        $this->assertSame([['Shop Status', 'Open', '📼', true]], self::dataFields($embed));
    }

    public function testShopStatusUnknown(): void
    {
        $shopStatus = new GameNotesStateMetric()->setName('Shop Status')->setTargetValue(ZenlessZoneZeroVhsSale::DONE);

        $embed = new ShopStatusPresenter(self::embedFactory())->present(self::profile('Zenless Zone Zero'), $shopStatus, 'Shop Status');

        $this->assertSame([], self::dataFields($embed));
    }
}
