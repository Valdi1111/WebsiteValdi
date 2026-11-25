<?php

namespace App\Tests\GamesBundle\Status;

use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Model\Game\CheckInStatus;
use App\GamesBundle\Model\Game\HasCheckInStatusInterface;
use App\GamesBundle\Model\Profile\ProfileSetting;
use App\GamesBundle\Model\Profile\ProfileSettingKind;
use App\GamesBundle\Model\Status\CheckInData;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Profile\ProfileSettingsReader;
use App\GamesBundle\Status\Section\CheckInSection;
use App\Tests\GamesBundle\Status\Fixtures\FakeCheckInGameService;
use App\Tests\GamesBundle\Status\Fixtures\FakeGameProfile;
use App\Tests\GamesBundle\Status\Fixtures\FakeProfileProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PropertyAccess\PropertyAccess;

class CheckInSectionTest extends TestCase
{
    private FakeProfileProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new FakeProfileProvider();
        // The auto check-in flag of the fake profile is its "active" field
        $this->provider->settings = [
            new ProfileSetting('check_in', 'Daily check-in', ProfileSettingKind::Automation, HasCheckInStatusInterface::class, 'active'),
        ];
    }

    private function section(): CheckInSection
    {
        return new CheckInSection(
            new GameProfileEmbedFactory(new MockClock('2026-11-22 10:00:00')),
            new ProfileSettingsReader(
                new GameProfileRegistry([$this->provider], $this->createStub(UserRoleResolver::class), ['fake' => 'ROLE_FAKE']),
                PropertyAccess::createPropertyAccessor(),
            ),
        );
    }

    private function collect(FakeCheckInGameService $service, bool $autoCheckIn = true, bool $fresh = false): CheckInData
    {
        $this->provider->services = ['fake_game' => $service];
        $profile = FakeGameProfile::ownedBy(GameProfileRegistryTest::user(1), 1, active: $autoCheckIn);

        return $this->section()->collect($this->provider->createContext($profile, $fresh));
    }

    public function testCollectReadsTheStatusAndTheAutoCheckIn(): void
    {
        $service = new FakeCheckInGameService(new CheckInStatus(true, 12, new \DateTimeImmutable('2026-11-22 09:55:00')));

        $data = $this->collect($service, autoCheckIn: false, fresh: true);

        $this->assertSame([true], $service->reads);
        $this->assertTrue($data->status->signedToday);
        $this->assertFalse($data->autoCheckIn);
        $this->assertEquals(new \DateTimeImmutable('2026-11-22 09:55:00'), $data->getFetchedAt());
    }

    public function testSupportsOnlyGamesReportingTheCheckIn(): void
    {
        $this->provider->services = ['fake_game' => new FakeCheckInGameService(new CheckInStatus(false)), 'other_game' => new \stdClass()];
        $user = GameProfileRegistryTest::user(1);

        $this->assertTrue($this->section()->supports($this->provider->createContext(FakeGameProfile::ownedBy($user, 1))));
        $this->assertFalse($this->section()->supports($this->provider->createContext(FakeGameProfile::ownedBy($user, 2, 'other_game'))));
        $this->provider->withAccountData = false;
        $this->assertFalse($this->section()->supports($this->provider->createContext(FakeGameProfile::ownedBy($user, 1))));
    }

    public function testSummaryAsksForAttentionOnlyWithoutAutoCheckIn(): void
    {
        $section = $this->section();
        $profile = FakeGameProfile::ownedBy(GameProfileRegistryTest::user(1), 1);

        $this->assertEquals(new SummaryLine('Check-in', 'Done · day 12', '🎁'), $section->summarize($profile, new CheckInData(new CheckInStatus(true, 12), true)));
        $this->assertEquals(new SummaryLine('Check-in', 'Done', '🎁'), $section->summarize($profile, new CheckInData(new CheckInStatus(true), false)));
        $this->assertEquals(new SummaryLine('Check-in', 'Not done today · auto check-in on', '🎁'), $section->summarize($profile, new CheckInData(new CheckInStatus(false, 11), true)));
        $this->assertEquals(new SummaryLine('Check-in', 'Not done today', '🎁', true), $section->summarize($profile, new CheckInData(new CheckInStatus(false, 11), false)));
    }

    public function testFullView(): void
    {
        $embed = $this->section()->present(FakeGameProfile::ownedBy(GameProfileRegistryTest::user(1), 1), new CheckInData(new CheckInStatus(false, 11), true));

        $this->assertSame('Fake Game - Check-in', $embed->getTitle());
        $this->assertSame([
            ['Today', 'Not claimed yet', '🎁'],
            ['Days Claimed', '11', '📆'],
            ['Auto Check-In', 'On', '🔁'],
        ], array_map(static fn (EmbedField $field): array => [$field->getName(), $field->getValue(), $field->getIcon()], array_slice($embed->getFields(), 3)));
    }
}
