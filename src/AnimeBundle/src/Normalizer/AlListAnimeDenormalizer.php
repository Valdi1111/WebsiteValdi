<?php

namespace App\AnimeBundle\Normalizer;

use App\AnimeBundle\Model\AlListAnime;
use App\AnimeBundle\Model\ListAnimeStatus;
use App\AnimeBundle\Model\ListAnimeType;
use App\AnimeBundle\Model\Nsfw;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class AlListAnimeDenormalizer implements DenormalizerInterface
{
    public function getSupportedTypes(?string $format): array
    {
        return [
            AlListAnime::class => true,
        ];
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_array($data) && $type === AlListAnime::class;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): AlListAnime
    {
        $media = $data['media'] ?? [];
        $rawStatus = (string) ($data['status'] ?? '');

        return new AlListAnime()
            ->setId((int) ($media['id'] ?? 0))
            ->setTitle((string) ($media['title']['romaji'] ?? ''))
            ->setTitleEn((string) ($media['title']['english'] ?? ''))
            ->setNsfw(!empty($media['isAdult']) ? Nsfw::black : Nsfw::white)
            ->setNumEpisodes((int) ($media['episodes'] ?? 0))
            ->setStatus($this->mapAnimeStatus($rawStatus))
            ->setMediaType($this->mapAnimeType($media['format'] ?? null));
    }

    private function mapAnimeStatus(string $status): ListAnimeStatus
    {
        return match ($status) {
            'CURRENT' => ListAnimeStatus::watching,
            'COMPLETED' => ListAnimeStatus::completed,
            'PAUSED' => ListAnimeStatus::on_hold,
            'DROPPED' => ListAnimeStatus::dropped,
            'PLANNING' => ListAnimeStatus::plan_to_watch,
            default => ListAnimeStatus::watching,
        };
    }

    private function mapAnimeType(?string $format): ListAnimeType
    {
        return match ($format) {
            'TV' => ListAnimeType::tv,
            'TV_SHORT' => ListAnimeType::tv_special,
            'MOVIE' => ListAnimeType::movie,
            'OVA' => ListAnimeType::ova,
            'ONA' => ListAnimeType::ona,
            'SPECIAL' => ListAnimeType::special,
            'MUSIC' => ListAnimeType::music,
            default => ListAnimeType::tryFrom(strtolower((string) $format)) ?? ListAnimeType::unknown,
        };
    }
}
