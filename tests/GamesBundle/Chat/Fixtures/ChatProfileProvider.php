<?php

namespace App\Tests\GamesBundle\Chat\Fixtures;

use App\GamesBundle\Model\Game\GameProfileInterface;
use App\Tests\GamesBundle\Status\Fixtures\FakeProfileProvider;

/**
 * Fake provider where single profiles can be unreadable (no context: an expired cookie), the others staying readable.
 */
class ChatProfileProvider extends FakeProfileProvider
{
    /** @var array<int, \Throwable> profile id => error of its context */
    public array $profileErrors = [];

    protected function findGameService(GameProfileInterface $profile): ?object
    {
        $error = $this->profileErrors[(int) $profile->getId()] ?? null;
        if ($error !== null) {
            throw $error;
        }

        return parent::findGameService($profile);
    }
}
