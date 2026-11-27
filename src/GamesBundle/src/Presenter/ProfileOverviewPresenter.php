<?php

namespace App\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\Discord\DiscordEmbedLimiter;
use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\EndgameSettings;
use App\GamesBundle\Model\Endgame\EndgameTarget;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Profile\ProfileSettingKind;
use App\GamesBundle\Model\Profile\ProfileSettingValue;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Profile\ProfileSettingsReader;
use Psr\Log\LoggerInterface;

/**
 * What the site knows of a game profile, from the database only (/profiles): its state, the login of its account, the
 * reminders and automations turned on with their thresholds, the endgame targets and where its notifications go.
 *
 * The settings need the context of the profile, built without API calls but not always buildable (unreadable cookie):
 * such a profile still shows what needs no context (identity, state, login, notification platforms) with a line saying
 * its settings could not be read. The error itself stays in the logs.
 */
class ProfileOverviewPresenter
{
    public const string TITLE = 'Profile';
    public const string NONE = 'None';
    // The site bypasses every background task of a suspended profile, whatever its settings say
    public const string SUSPENDED = 'Suspended · no reminder, automation or sync runs';
    public const string SETTINGS_UNAVAILABLE = 'The settings of this profile could not be read right now.';

    /** Room left in a field value for the icon the Discord transformer prepends to it */
    private const int MAX_VALUE = DiscordEmbedLimiter::MAX_FIELD_VALUE - 16;

    /** Values of the notification platforms of a profile (UnifiedNotificationService), lowercase */
    private const array PLATFORMS = ['discord' => 'Discord', 'telegram' => 'Telegram'];

    public function __construct(
        private readonly GameProfileEmbedFactory $embedFactory,
        private readonly GameProfileRegistry $registry,
        private readonly ProfileSettingsReader $settingsReader,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function present(GameProfileInterface $profile): UniversalEmbed
    {
        $provider = $this->registry->providerOf($profile);
        $account = $profile->getAccount();
        $health = $provider !== null && $account !== null ? $provider->getAccountHealth($account) : AccountHealth::ok();

        // Only the context is guarded: a failure past it is a bug, not an unreadable profile
        $context = null;
        try {
            $context = $provider?->createContext($profile);
        } catch (\Throwable $e) {
            // Class and code only, as the status collector logs: the message can carry account data
            $this->logger->warning('The game profile could not be read: {error}', [
                'profile' => sprintf('%s:%s', $provider?->getKey() ?? '?', $profile->getId() ?? '?'),
                'error' => $e::class,
                'code' => $e->getCode(),
            ]);
        }

        $embed = $this->embedFactory->create($profile, self::TITLE, $context === null ? '⚠️ ' . self::SETTINGS_UNAVAILABLE : null);
        $active = $profile->isActive() === true;
        $embed
            ->addField('Status', $active ? 'Active' : self::SUSPENDED, icon: $active ? '✅' : '⏸️', inline: true)
            ->addField('Account', $health->ok ? 'OK' : (string) $health->problem, icon: $health->ok ? '🔑' : '⚠️', inline: true);

        if ($context !== null) {
            $this->addSettings($embed, $context);
        }

        return $embed->addField('Notifications', self::lines($this->platformsOf($profile)), icon: '📨');
    }

    /**
     * Reminders and automations always (None when all are off), syncs and endgame targets only for a game that has
     * them.
     */
    private function addSettings(UniversalEmbed $embed, GameProfileContext $context): void
    {
        $values = $this->settingsReader->read($context);

        $enabled = [];
        foreach ($values as $value) {
            if ($value->enabled) {
                $enabled[$value->setting->kind->value][] = $value->setting->kind === ProfileSettingKind::Reminder
                    ? self::describeReminder($value)
                    : $value->setting->label;
            }
        }

        $embed
            ->addField('Reminders', self::lines($enabled[ProfileSettingKind::Reminder->value] ?? []), icon: '🔔')
            ->addField('Automations', self::lines($enabled[ProfileSettingKind::Automation->value] ?? []), icon: '🤖');

        $hasSync = array_filter($values, static fn (ProfileSettingValue $value): bool => $value->setting->kind === ProfileSettingKind::Sync) !== [];
        if ($hasSync) {
            $embed->addField('Sync', self::lines($enabled[ProfileSettingKind::Sync->value] ?? []), icon: '🔄');
        }

        if ($context->gameService instanceof HasEndgamesInterface) {
            $embed->addField('Endgame targets', self::lines(self::describeEndgameTargets($context->gameService, $values['endgames'] ?? null)), icon: '🎯');
        }
    }

    /**
     * "Stamina reminder (≥ 180)"; a setting with a threshold field and no threshold (null or negative) reminds when full.
     */
    private static function describeReminder(ProfileSettingValue $value): string
    {
        if ($value->setting->thresholdField === null) {
            return $value->setting->label;
        }

        return $value->threshold !== null && $value->threshold >= 0
            ? sprintf('%s (≥ %d)', $value->setting->label, $value->threshold)
            : sprintf('%s (when full)', $value->setting->label);
    }

    /**
     * One line per variant: the mode name for a mode with a single variant, else the variant name (which already holds
     * the mode name, e.g. "Deadly Assault (Hard)"). Targets not stored take the defaults of the game.
     *
     * @return list<string>
     */
    private static function describeEndgameTargets(HasEndgamesInterface $service, ?ProfileSettingValue $setting): array
    {
        $definitions = $service::getEndgameDefinitions();
        $targets = EndgameSettings::resolve($definitions, $setting?->extra['endgamesSettings'] ?? null);

        $lines = [];
        foreach ($definitions as $definition) {
            foreach ($definition->variants as $variant) {
                $name = count($definition->variants) > 1 ? $variant->name : $definition->name;
                $lines[] = sprintf('%s: %s', $name, self::describeTarget($targets[$definition->key][$variant->key]));
            }
        }

        return $lines;
    }

    /**
     * The labels of the settings drawer on the site; a level stands for a Full Clear up to it.
     */
    private static function describeTarget(EndgameTarget $target): string
    {
        return $target->level?->name ?? match ($target->reminder) {
            EndgameReminder::Off => 'Off',
            EndgameReminder::NotPlayed => 'Not Played',
            EndgameReminder::FullClear => 'Full Clear',
        };
    }

    /**
     * @return list<string>
     */
    private function platformsOf(GameProfileInterface $profile): array
    {
        $labels = [];
        foreach ($profile->getNotificationPlatforms() as $platform) {
            if (!is_string($platform) || trim($platform) === '') {
                continue;
            }
            $key = strtolower(trim($platform));
            $labels[$key] = self::PLATFORMS[$key] ?? ucfirst($key);
        }

        return array_values($labels);
    }

    /**
     * One item per line, "None" for no item. A field value holds 1024 characters: past them the list is cut at a whole
     * line, saying how many items are left out.
     *
     * @param list<string> $items
     */
    private static function lines(array $items): string
    {
        if ($items === []) {
            return self::NONE;
        }
        $items = array_map(static fn (string $item): string => DiscordEmbedLimiter::truncate($item, DiscordEmbedLimiter::MAX_FIELD_NAME), $items);
        $value = implode("\n", $items);
        if (mb_strlen($value) <= self::MAX_VALUE) {
            return $value;
        }

        $kept = [];
        foreach ($items as $item) {
            $candidate = [...$kept, $item];
            if (mb_strlen(implode("\n", [...$candidate, self::more(count($items) - count($candidate))])) > self::MAX_VALUE) {
                break;
            }
            $kept = $candidate;
        }

        return implode("\n", [...$kept, self::more(count($items) - count($kept))]);
    }

    private static function more(int $count): string
    {
        return sprintf('… and %d more', $count);
    }
}
