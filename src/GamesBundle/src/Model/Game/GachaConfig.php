<?php

declare(strict_types=1);

namespace App\GamesBundle\Model\Game;

class GachaConfig
{
    /**
     * @var array<string, GachaBannerConfig>
     */
    private array $banners = [];

    /**
     * @param list<GachaBannerConfig> $banners
     */
    public function __construct(
        private string $defaultEndpointUrl = '',
        array $banners = [],
    ) {
        $this->setBanners($banners);
    }

    public function getDefaultEndpointUrl(): string
    {
        return $this->defaultEndpointUrl;
    }

    public function setDefaultEndpointUrl(string $defaultEndpointUrl): static
    {
        $this->defaultEndpointUrl = $defaultEndpointUrl;

        return $this;
    }

    /**
     * @return array<string, GachaBannerConfig>
     */
    public function getBanners(): array
    {
        return $this->banners;
    }

    /**
     * @param iterable<GachaBannerConfig> $banners
     */
    public function setBanners(iterable $banners): static
    {
        $this->banners = [];
        foreach ($banners as $banner) {
            $this->addBanner($banner);
        }

        return $this;
    }

    public function addBanner(GachaBannerConfig $banner): static
    {
        $this->banners[$banner->getKey()] = $banner;

        return $this;
    }

    public function getBanner(string|int $gachaType): ?GachaBannerConfig
    {
        $lookup = (string) $gachaType;
        if (isset($this->banners[$lookup])) {
            return $this->banners[$lookup];
        }

        foreach ($this->banners as $banner) {
            if (in_array($lookup, $banner->getGachaTypes(), true)) {
                return $banner;
            }
        }

        return null;
    }

    public function getEndpointUrlForBanner(string|int $gachaType): string
    {
        return $this->getBanner($gachaType)?->getEndpointUrl() ?? $this->defaultEndpointUrl;
    }

    /**
     * @return array<string, string> banner_key => display_name
     */
    public function getBannerTypes(): array
    {
        $types = [];
        foreach ($this->banners as $key => $banner) {
            $types[$key] = $banner->getName();
        }

        return $types;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getBannerGroups(string $typesKey = 'gacha_types'): array
    {
        $groups = [];
        foreach ($this->banners as $key => $banner) {
            $groups[$key] = $banner->toGroupArray($typesKey);
        }

        return $groups;
    }
}
