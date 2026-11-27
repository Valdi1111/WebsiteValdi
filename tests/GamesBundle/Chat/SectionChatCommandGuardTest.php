<?php

namespace App\Tests\GamesBundle\Chat;

use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatUser;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Chat\Command\AbstractSectionChatCommand;
use App\GamesBundle\Model\Chat\ChatProfileSelection;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\ProfileSummaryPresenter;
use App\GamesBundle\Presenter\StaminaPresenter;
use App\GamesBundle\Service\DurationFormatter;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use App\GamesBundle\Status\Section\StaminaSection;
use App\Tests\CoreBundle\Chat\Fixtures\RecordingLogger;
use App\Tests\GamesBundle\Status\Fixtures\FakeGameProfile;
use App\Tests\GamesBundle\Status\Fixtures\FakeNotesGameService;
use App\Tests\GamesBundle\Status\Fixtures\FakeProfileProvider;
use App\Tests\GamesBundle\Status\GameProfileRegistryTest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * A section whose full view throws on unexpected data shows the generic line for that profile, logged by class only,
 * and never fails the other profiles of the answer.
 */
class SectionChatCommandGuardTest extends TestCase
{
    public function testSectionThatCannotBeLaidOutIsUnavailableForThatProfileOnly(): void
    {
        $clock = new MockClock('2026-11-27 10:00:00');
        $factory = new GameProfileEmbedFactory($clock);
        $user = GameProfileRegistryTest::user(1);

        $provider = new FakeProfileProvider();
        $provider->services = ['fake_game' => new FakeNotesGameService()];
        $provider->profiles = [FakeGameProfile::ownedBy($user, 1), FakeGameProfile::ownedBy($user, 2)];
        $registry = new GameProfileRegistry([$provider], $this->createStub(UserRoleResolver::class), ['fake' => 'ROLE_FAKE']);

        // The stamina section, whose full view fails for the first profile only
        $section = new class(new StaminaPresenter($factory, new DurationFormatter()), new DurationFormatter(), $clock) extends StaminaSection {
            public function present(GameProfileInterface $profile, object $data): UniversalEmbed
            {
                if ($profile->getId() === 1) {
                    throw new \LogicException('value=secret', 7);
                }

                return parent::present($profile, $data);
            }
        };
        $collector = new GameProfileStatusCollector($registry, [$section], new NullLogger());
        $logger = new RecordingLogger();

        $command = new class($collector, new ProfileSummaryPresenter($factory, new DurationFormatter()), $factory, $logger) extends AbstractSectionChatCommand {
            protected function getSectionKey(): string
            {
                return 'stamina';
            }

            protected function getSubject(): string
            {
                return 'stamina';
            }

            /**
             * @return list<UniversalEmbed>
             */
            public function embedsOf(ChatUser $user, ChatProfileSelection $selection): array
            {
                return $this->buildEmbeds($user, $selection, false);
            }
        };

        $embeds = $command->embedsOf(
            new ChatUser($user, ChatPlatform::Cli, '1'),
            new ChatProfileSelection(null, null, $provider->profiles, [new GameProfileRef('fake', 1), new GameProfileRef('fake', 2)]),
        );

        $this->assertCount(2, $embeds);
        $this->assertSame('⚠️ ' . ProfileSummaryPresenter::UNAVAILABLE, $embeds[0]->getDescription());
        $this->assertSame('Stamina', $embeds[1]->getFields()[3]->getName());
        $this->assertCount(1, $logger->records);
        $this->assertStringNotContainsString('secret', json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }
}
