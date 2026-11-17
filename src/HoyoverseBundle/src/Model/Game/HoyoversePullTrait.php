<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Exception\HoyolabException;
use App\HoyoverseBundle\Model\Pull\HoyoversePullItem;
use Symfony\Component\HttpFoundation\Request;

/**
 * @mixin GameInterface
 * @mixin HasPullTrackerInterface
 */
trait HoyoversePullTrait
{
    /**
     * @param array<string, mixed> $extraQueryParams
     *
     * @return array<HoyoversePull>
     */
    public function fetchPullsPage(
        HoyoverseGameProfile $profile,
        string|int $gachaType,
        ?string $authkey = null,
        ?string $endId = '0',
        array $extraQueryParams = [],
        int $size = 20
    ): array {
        if ($authkey === null || $authkey === '') {
            if ($this instanceof HasStokenPullImportInterface) {
                $authkey = $this->getCookieUtils()->generateAuthKey($profile, $this->getAuthkeyAppId());
            } else {
                throw new HoyolabException('Authkey is required for pulling records.');
            }
        }

        $gachaConfig = $this->getGachaConfig();

        $query = array_merge([
            'authkey_ver' => '1',
            'sign_type' => '2',
            'lang' => 'en',
            'authkey' => $authkey,
            'game_biz' => $profile->getGameBiz(),
            'gacha_type' => (string) $gachaType,
            'real_gacha_type' => (string) $gachaType,
            'size' => $size,
            'end_id' => $endId ?? '0',
        ], $extraQueryParams);

        // Ensure raw authkey is properly passed in query
        try {
            $response = $this->getHoyolabClient()->request(
                Request::METHOD_GET,
                $gachaConfig->getEndpointUrlForBanner($gachaType),
                ['query' => $query]
            );

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new HoyolabException(
                sprintf('Failed to fetch gacha logs for %s: %s', $profile->getNickname(), $e->getMessage()),
                0,
                $e
            );
        }

        if (($data['retcode'] ?? -1) !== 0) {
            throw new HoyolabException(sprintf(
                'Gacha log API error for %s: %s (retcode: %d)',
                $profile->getNickname(),
                $data['message'] ?? 'Unknown error',
                $data['retcode'] ?? -1
            ));
        }

        $rawList = $data['data']['list'] ?? [];
        if (empty($rawList)) {
            return [];
        }

        /** @var array<HoyoversePullItem> $pullItems */
        $pullItems = $this->getDenormalizer()->denormalize(
            $rawList,
            HoyoversePullItem::class . '[]'
        );

        $defaultBannerName = $gachaConfig->getBanner($gachaType)?->getName();
        $pulls = [];

        foreach ($pullItems as $index => $item) {
            /** @var HoyoversePull $pull */
            $pull = $this->getObjectMapper()->map($item, HoyoversePull::class);
            $pull->setGameProfile($profile);
            $itemGachaType = $pull->getGachaType();
            $resolvedName = ($itemGachaType !== null ? $gachaConfig->getBanner($itemGachaType)?->getName() : null)
                ?? $defaultBannerName;
            if ($resolvedName !== null) {
                $pull->setGachaName($resolvedName);
            }
            $pull->setRawPayload($rawList[$index] ?? null);
            $pulls[] = $pull;
        }

        return $pulls;
    }
}
