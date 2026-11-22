<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasChroniclePullImportInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\Pull\PullSyncSource;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;

/**
 * Decides whether a profile can sync its pulls automatically (manual "Sync Pulls" and nightly sync), and from where,
 * using both the game capabilities and the account cookie:
 * - stoken_v2 present and the game generates authkeys from it: authkey + getGachaLog, as before;
 * - otherwise, HoYoLAB web cookies and a game listing pulls in the battle chronicle: battle chronicle;
 * - otherwise (e.g. Genshin Impact without stoken_v2, Honkai: Star Rail): no automatic sync.
 */
class PullSyncSourceResolver
{
    public function __construct(
        private readonly HoyolabCookieUtilsService $cookieUtils,
    ) {
    }

    public function resolveForProfile(GameInterface $gameService, HoyoverseGameProfile $profile): ?PullSyncSource
    {
        return $this->resolve($gameService, $this->cookieUtils->tryParseCookie($profile->getAccount()?->getCookie()));
    }

    public function resolve(GameInterface $gameService, ?ParsedCookie $cookie): ?PullSyncSource
    {
        if ($cookie === null || !$cookie->isValid()) {
            return null;
        }

        if ($gameService instanceof HasStokenPullImportInterface && $cookie->canAutoRenew()) {
            return PullSyncSource::Stoken;
        }

        if ($gameService instanceof HasChroniclePullImportInterface) {
            return PullSyncSource::Chronicle;
        }

        return null;
    }
}
