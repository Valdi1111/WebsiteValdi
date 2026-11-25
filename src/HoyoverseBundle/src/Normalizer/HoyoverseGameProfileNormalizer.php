<?php

namespace App\HoyoverseBundle\Normalizer;

use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\EndgameLevel;
use App\GamesBundle\Model\Endgame\EndgameSettings;
use App\GamesBundle\Model\Endgame\EndgameVariant;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\HasManualPullsInterface;
use App\GamesBundle\Model\Game\HasSRankHistoryImportInterface;
use App\GamesBundle\Model\Profile\ProfileSetting;
use App\GamesBundle\Normalizer\AbstractGameProfileNormalizer;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasPowershellPullImportInterface;
use App\HoyoverseBundle\Model\Game\HasPullTrackerInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Status\HoyoverseProfileSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class HoyoverseGameProfileNormalizer extends AbstractGameProfileNormalizer
{
    /** Serialized name of HoyoverseGameProfile::$endgamesSettings (the property name is accepted as well) */
    public const string ENDGAMES_SETTINGS_FIELD = 'endgames_settings';


    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     * @param array<string, string>                     $timezones
     * @param HoyolabCookieUtilsService|null            $cookieUtils Lazy: it needs the serializer, which needs this normalizer
     */
    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        NormalizerInterface&DenormalizerInterface $objectNormalizer,
        #[AutowireLocator(services: 'hoyoverse.game.id', indexAttribute: 'key')]
        ServiceCollectionInterface $locatorByGameId,
        #[Autowire(param: 'hoyoverse.timezones')]
        private readonly array $timezones = [],
        #[Autowire(lazy: true)]
        private readonly ?HoyolabCookieUtilsService $cookieUtils = null,
        #[Autowire(lazy: true)]
        ?GameProfileStatusCollector $statusCollector = null,
    ) {
        parent::__construct($objectNormalizer, $locatorByGameId, $statusCollector);
    }

    protected function getSupportedClass(): string
    {
        return HoyoverseGameProfile::class;
    }

    protected function getConditionalSettings(): array
    {
        // The fields of each capability come from the settings of the provider, their single definition
        return ProfileSetting::fieldsByCapability(HoyoverseProfileSettings::all());
    }

    protected function getTimezoneMap(): array
    {
        return $this->timezones;
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $result = parent::normalize($data, $format, $context);

        if (is_array($result) && $data instanceof HoyoverseGameProfile) {
            $gameId = (int) $data->getGameId();
            if ($this->locatorByGameId->has($gameId)) {
                $gameService = $this->locatorByGameId->get($gameId);
                $result['supports_pulls'] = $gameService instanceof HasPullTrackerInterface;
                // Missing pulls (manual S pulls, declared pities) can be added to the pull tracker
                $result['supports_manual_pulls'] = $gameService instanceof HasManualPullsInterface;
                // Server-side automatic sync (SToken); shared flag name across providers
                $result['supports_pull_sync'] = $gameService instanceof HasStokenPullImportInterface;
                $result['supports_powershell_pulls'] = $gameService instanceof HasPowershellPullImportInterface;
                // Official S-rank history import: the game reads it with the HoYoLAB cookie, which must be usable
                $result['supports_s_rank_history_import'] = $gameService instanceof HasPullTrackerInterface
                    && $gameService instanceof HasManualPullsInterface
                    && $gameService instanceof HasSRankHistoryImportInterface
                    && $this->cookieUtils?->tryParseCookie($data->getAccount()?->getCookie()) !== null;
                // Game-specific name of pulls (e.g. "Wishes", "Signals"), used for UI labels
                $result['pull_label'] = $gameService instanceof HasPullTrackerInterface
                    ? $gameService->getRarityConfig()->getPullLabel()
                    : null;

                if ($gameService instanceof HasEndgamesInterface) {
                    // Every mode and variant with its effective value, and the modes to build the settings form from
                    // (the levels only for the variants that have some, which take them in place of "full_clear")
                    $definitions = $gameService::getEndgameDefinitions();
                    $result[self::ENDGAMES_SETTINGS_FIELD] = EndgameSettings::normalize($definitions, $data->getEndgamesSettings());
                    $result['endgames'] = array_map(static fn (EndgameDefinition $definition) => [
                        'key' => $definition->key,
                        'name' => $definition->name,
                        'variants' => array_map(static fn (EndgameVariant $variant) => [
                            'key' => $variant->key,
                            'name' => $variant->name,
                            'default' => $variant->getDefaultTarget()->getValue(),
                        ] + ($variant->hasLevels() ? [
                            'levels' => array_map(static fn (EndgameLevel $level) => [
                                'key' => $level->key,
                                'name' => $level->name,
                            ], $variant->levels),
                        ] : []), $definition->variants),
                    ], $definitions);
                }
            }
        }

        return $result;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (is_array($data)) {
            $data = $this->sanitizeEndgamesSettings($data, $context);
        }

        return parent::denormalize($data, $type, $format, $context);
    }

    /**
     * The settings JSON is written as a whole: only the modes, variants and values declared by the game are kept,
     * merged over the stored ones (a partial update changes only what it sends). Dropped for games without endgames.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeEndgamesSettings(array $data, array $context): array
    {
        $fields = array_values(array_filter(
            [self::ENDGAMES_SETTINGS_FIELD, 'endgamesSettings'],
            static fn (string $field) => array_key_exists($field, $data)
        ));
        if ($fields === []) {
            return $data;
        }

        $submitted = $data[$fields[0]];
        foreach ($fields as $field) {
            unset($data[$field]);
        }

        $target = $context[AbstractNormalizer::OBJECT_TO_POPULATE] ?? null;
        $gameId = $target instanceof HoyoverseGameProfile ? $target->getGameId() : ($data['game_id'] ?? null);
        if (!is_numeric($gameId) || !$this->locatorByGameId->has((int) $gameId)) {
            return $data;
        }

        $gameService = $this->locatorByGameId->get((int) $gameId);
        if (!$gameService instanceof HasEndgamesInterface) {
            return $data;
        }

        $stored = $target instanceof HoyoverseGameProfile ? ($target->getEndgamesSettings() ?? []) : [];
        $data[self::ENDGAMES_SETTINGS_FIELD] = EndgameSettings::normalize(
            $gameService::getEndgameDefinitions(),
            is_array($submitted) ? array_replace_recursive($stored, $submitted) : $stored
        );

        return $data;
    }
}
