<?php

namespace App\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\EndgameModeStatus;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Service\DurationFormatter;

/**
 * One row per endgame mode: each variant (or an empty field when it is hidden, locked or missing), then "Ends in".
 * Rows are three inline fields, so that Discord keeps each mode on its own line.
 */
class EndgamesPresenter
{
    /** Empty field completing a row on Discord (Telegram skips it) */
    public const string FILLER = "\u{200b}";

    public function __construct(
        private readonly GameProfileEmbedFactory $embedFactory,
        private readonly DurationFormatter $durationFormatter,
    ) {
    }

    /**
     * @param list<EndgameModeStatus> $modes
     * @param \DateTimeImmutable      $now   start of the "Ends in" countdown
     */
    public function present(
        GameProfileInterface $profile,
        array $modes,
        \DateTimeImmutable $now,
        string $title,
        ?string $description = null,
    ): UniversalEmbed {
        $embed = $this->embedFactory->create($profile, $title, $description);
        foreach ($modes as $mode) {
            foreach ($this->createRow($mode, $now) as $field) {
                $embed->addEmbedField($field);
            }
        }

        return $embed;
    }

    /**
     * @return list<EmbedField>
     */
    private function createRow(EndgameModeStatus $mode, \DateTimeImmutable $now): array
    {
        $byVariant = [];
        foreach ($mode->endgames as $endgame) {
            $byVariant[$endgame->getVariant()] = $endgame;
        }

        $fields = [];
        foreach ($mode->definition->variants as $variant) {
            $endgame = $byVariant[$variant->key] ?? null;
            $fields[] = $endgame === null || !$endgame->isUnlocked() || in_array($variant->key, $mode->hiddenVariants, true)
                ? self::createFiller()
                : self::createVariantField($variant->name, $endgame);
        }
        while (count($fields) < EndgameDefinition::MAX_VARIANTS) {
            $fields[] = self::createFiller();
        }

        $fields[] = new EmbedField()
            ->setName('Ends in')
            ->setValue($this->durationFormatter->format(max(0, $mode->endTime->getTimestamp() - $now->getTimestamp()), 2))
            ->setIcon('🕒')
            ->setInline(true);

        return $fields;
    }

    private static function createVariantField(string $name, GameEndgame $endgame): EmbedField
    {
        if (!$endgame->isPlayed()) {
            return new EmbedField()->setName($name)->setValue('Not played yet')->setIcon('⚔️')->setInline(true);
        }

        return new EmbedField()
            ->setName($name)
            ->setValue(implode("\n", $endgame->getProgressLines()) ?: 'Played')
            // Lines with their own icons (e.g. one per floor) are not prefixed by another one
            ->setIcon($endgame->hasProgressIcons() ? null : ($endgame->isMaxed() ? '✅' : '⚔️'))
            ->setInline(true);
    }

    private static function createFiller(): EmbedField
    {
        return new EmbedField()->setName(self::FILLER)->setValue(self::FILLER)->setInline(true);
    }
}
