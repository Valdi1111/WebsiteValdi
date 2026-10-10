<?php

namespace App\AnimeBundle\Normalizer;

use App\AnimeBundle\Model\AlListManga;
use App\AnimeBundle\Model\ListMangaStatus;
use App\AnimeBundle\Model\ListMangaType;
use App\AnimeBundle\Model\Nsfw;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class AlListMangaDenormalizer implements DenormalizerInterface
{
    public function getSupportedTypes(?string $format): array
    {
        return [
            AlListManga::class => true,
        ];
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_array($data) && $type === AlListManga::class;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): AlListManga
    {
        $media = $data['media'] ?? [];
        $rawStatus = (string) ($data['status'] ?? '');

        return new AlListManga()
            ->setId((int) ($media['id'] ?? 0))
            ->setTitle((string) ($media['title']['romaji'] ?? ''))
            ->setTitleEn((string) ($media['title']['english'] ?? ''))
            ->setNsfw(!empty($media['isAdult']) ? Nsfw::black : Nsfw::white)
            ->setNumVolumes((int) ($media['volumes'] ?? 0))
            ->setNumChapters((int) ($media['chapters'] ?? 0))
            ->setStatus($this->mapMangaStatus($rawStatus))
            ->setMediaType($this->mapMangaType($media['format'] ?? null, $media['countryOfOrigin'] ?? null));
    }

    private function mapMangaStatus(string $status): ListMangaStatus
    {
        return match ($status) {
            'CURRENT' => ListMangaStatus::reading,
            'COMPLETED' => ListMangaStatus::completed,
            'PAUSED' => ListMangaStatus::on_hold,
            'DROPPED' => ListMangaStatus::dropped,
            'PLANNING' => ListMangaStatus::plan_to_read,
            default => ListMangaStatus::reading,
        };
    }

    private function mapMangaType(?string $format, ?string $countryOfOrigin): ListMangaType
    {
        if ($format === 'MANGA') {
            return match ($countryOfOrigin) {
                'KR' => ListMangaType::manhwa,
                'CN', 'TW' => ListMangaType::manhua,
                default => ListMangaType::manga,
            };
        }

        return match ($format) {
            'NOVEL' => ListMangaType::light_novel,
            'ONE_SHOT' => ListMangaType::one_shot,
            default => ListMangaType::tryFrom(strtolower((string) $format)) ?? ListMangaType::unknown,
        };
    }
}
