<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Exception\RetrievePullsDataException;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads pulls from the HoYoLAB battle chronicle and builds them exactly as the getGachaLog sync does (same pull_id,
 * gacha type, banner name, item type, item id, name, rarity and server wall-clock time), so the two sources share
 * the pull_id deduplication. Only raw_payload differs: it keeps the chronicle record as received.
 *
 * @mixin GameInterface
 * @mixin HasChroniclePullImportInterface
 */
trait ChroniclePullTrait
{
    public function fetchChroniclePullsPage(RuntimeAccountData $account, string $gachaType, string $endId = '0'): array
    {
        $chronicleGachaType = $this->getChronicleGachaTypes()[$gachaType] ?? null;
        if ($chronicleGachaType === null) {
            throw new RetrievePullsDataException(sprintf('Banner %s is not available in the HoYoLAB battle chronicle.', $gachaType));
        }

        $profile = $account->getGameProfile();

        // The HoYoLAB web cookies are enough: no authkey and no DS header
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlChroniclePulls(),
            auth: $account,
            query: [
                'uid' => $profile->getGameUid(),
                'region' => $profile->getRegion(),
                'gacha_type' => $chronicleGachaType,
                'end_id' => $endId,
            ],
            exceptionClass: RetrievePullsDataException::class
        );

        $records = $body['data']['gacha_item_list'] ?? [];
        if (!is_array($records) || $records === []) {
            return [];
        }

        $rarityConfig = $this->getRarityConfig();
        // The chronicle names the tiers with their letter (S/A/B), getGachaLog with the numeric rank_type
        $rarities = [
            'S' => $rarityConfig->getS()->getRarity(),
            'A' => $rarityConfig->getA()->getRarity(),
            'B' => $rarityConfig->getB()->getRarity(),
        ];
        $bannerName = $this->getGachaConfig()->getBanner($gachaType)?->getName();

        $pulls = [];
        foreach ($records as $record) {
            $pull = $this->createPullFromChronicleRecord($record, $gachaType, $rarities)
                ->setGameProfile($profile);
            if ($bannerName !== null) {
                $pull->setGachaName($bannerName);
            }
            $pulls[] = $pull;
        }

        return $pulls;
    }

    /**
     * @param array<string, int> $rarities chronicle rarity letter => rarity stored by getGachaLog
     */
    private function createPullFromChronicleRecord(mixed $record, string $gachaType, array $rarities): HoyoversePull
    {
        $id = is_array($record) ? ($record['id'] ?? null) : null;
        if ((!is_string($id) && !is_int($id)) || (string) $id === '') {
            throw new RetrievePullsDataException(sprintf('HoYoLAB battle chronicle returned a record without id (banner %s).', $gachaType));
        }
        $id = (string) $id;

        $name = $record['item_name'] ?? null;
        $itemType = $record['item_type'] ?? null;
        $rarity = is_string($record['rarity'] ?? null) ? ($rarities[$record['rarity']] ?? null) : null;
        $pulledAt = $this->parseChronicleDate($record['date'] ?? null);
        $invalidField = match (true) {
            !is_string($name) || $name === '' => 'item_name',
            !is_string($itemType) || $itemType === '' => 'item_type',
            $rarity === null => 'rarity',
            $pulledAt === null => 'date',
            default => null,
        };
        if ($invalidField !== null) {
            throw new RetrievePullsDataException(sprintf('Unexpected %s in HoYoLAB battle chronicle record %s (banner %s).', $invalidField, $id, $gachaType));
        }

        $itemId = $record['item_id'] ?? null;

        return (new HoyoversePull())
            ->setPullId($id)
            // The records carry no gacha type: the chronicle is queried per banner
            ->setGachaType($gachaType)
            // Unknown chronicle types are kept as received, like getGachaLog values
            ->setItemType($this->getChronicleItemTypes()[$itemType] ?? $itemType)
            ->setItemId(is_scalar($itemId) && (string) $itemId !== '' ? (string) $itemId : null)
            ->setName($name)
            ->setRarity($rarity)
            ->setPulledAt($pulledAt)
            ->setRawPayload($record);
    }

    /**
     * The chronicle date is the same server wall-clock time as getGachaLog's "time": it is tagged as UTC without any
     * conversion, like HoyoversePullItem does.
     */
    private function parseChronicleDate(mixed $date): ?\DateTimeImmutable
    {
        if (!is_array($date)) {
            return null;
        }

        $parts = [];
        foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $key) {
            if (!isset($date[$key]) || !is_numeric($date[$key])) {
                return null;
            }
            $parts[] = (int) $date[$key];
        }

        $formatted = sprintf('%04d-%02d-%02d %02d:%02d:%02d', ...$parts);
        $pulledAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $formatted, new \DateTimeZone('UTC'));

        // createFromFormat() rolls invalid values over (e.g. month 13): only an exact round trip is a real date
        return $pulledAt !== false && $pulledAt->format('Y-m-d H:i:s') === $formatted ? $pulledAt : null;
    }
}
