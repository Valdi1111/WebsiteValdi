<?php

namespace App\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\EmbedField;
use App\GamesBundle\Model\Status\GameProfileStatus;
use App\GamesBundle\Model\Status\SectionResult;
use App\GamesBundle\Model\Status\View\ProfileStatusFieldView;
use App\GamesBundle\Model\Status\View\ProfileStatusSectionView;
use App\GamesBundle\Model\Status\View\ProfileStatusView;
use Psr\Log\LoggerInterface;

/**
 * Status of one game profile for the site, from what GameProfileStatusCollector read: per section, the line of the
 * compact view and the full view of the bot without its profile header, so the page shows what the commands show.
 *
 * Errors stay generic, as in the embeds. A section whose data cannot be laid out (summarize() or present() throwing on
 * unexpected values) is shown as unavailable instead of failing the whole response.
 */
class ProfileStatusViewFactory
{
    public function __construct(
        private readonly GameProfileEmbedFactory $embedFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(GameProfileStatus $status): ProfileStatusView
    {
        return new ProfileStatusView(
            ref: (string) $status->ref,
            game: $status->game !== null ? ['slug' => $status->game->slug, 'name' => $status->game->name] : null,
            generatedAt: $this->embedFactory->now(),
            fetchedAt: $status->fetchedAt,
            account: $status->accountHealth,
            error: $status->error !== null ? ProfileSummaryPresenter::PROFILE_UNAVAILABLE : null,
            sections: array_values(array_map(fn (SectionResult $result): ProfileStatusSectionView => $this->createSection($status, $result), $status->sections)),
        );
    }

    private function createSection(GameProfileStatus $status, SectionResult $result): ProfileStatusSectionView
    {
        $section = $result->section;
        if (!$result->isSuccessful() || $result->data === null) {
            return self::unavailable($result);
        }

        try {
            $line = $section->summarize($status->profile, $result->data);
            $embed = $section->present($status->profile, $result->data);
        } catch (\Throwable $e) {
            // Class and code only, as the collector logs: messages can carry account data
            $this->logger->warning('A section of the game profile could not be shown: {error}', [
                'profile' => (string) $status->ref,
                'section' => $section->getKey(),
                'error' => $e::class,
                'code' => $e->getCode(),
            ]);

            return self::unavailable($result);
        }

        return new ProfileStatusSectionView(
            key: $section->getKey(),
            label: $line->label,
            icon: $line->icon,
            available: true,
            attention: $line->attention,
            value: $line->value,
            description: $embed->getDescription(),
            fields: array_map(
                static fn (EmbedField $field): ProfileStatusFieldView => new ProfileStatusFieldView(
                    (string) $field->getName(),
                    (string) $field->getValue(),
                    $field->getIcon(),
                    $field->isInline(),
                ),
                array_values(array_filter(
                    array_slice($embed->getFields(), GameProfileEmbedFactory::HEADER_FIELD_COUNT),
                    static fn (EmbedField $field): bool => !self::isFiller($field),
                )),
            ),
        );
    }

    /**
     * Empty field that only completes a row of inline fields on Discord (EndgamesPresenter::FILLER), skipped as the
     * Telegram renderer does.
     */
    private static function isFiller(EmbedField $field): bool
    {
        return trim(str_replace("\u{200b}", '', (string) $field->getName())) === '';
    }

    private static function unavailable(SectionResult $result): ProfileStatusSectionView
    {
        return new ProfileStatusSectionView(
            key: $result->section->getKey(),
            label: $result->section->getLabel(),
            icon: $result->section->getIcon(),
            available: false,
            attention: false,
            value: ProfileSummaryPresenter::UNAVAILABLE,
        );
    }
}
