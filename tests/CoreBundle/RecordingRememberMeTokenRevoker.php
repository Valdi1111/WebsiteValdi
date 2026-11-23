<?php

namespace App\Tests\CoreBundle;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Security\RememberMeTokenRevoker;

/**
 * RememberMeTokenRevoker for the HTTP tests, which run without a database (the remember-me logins are a table):
 * it records the calls. RememberMeTokenRevokerTest covers the real one.
 */
class RecordingRememberMeTokenRevoker extends RememberMeTokenRevoker
{
    /** @var list<array{string, bool}> user identifier and $keepCurrentDevice, per call */
    public array $calls = [];

    /** @noinspection PhpMissingParentConstructorInspection the parent needs a database connection */
    public function __construct()
    {
    }

    public function revoke(User $user, bool $keepCurrentDevice): void
    {
        $this->calls[] = [$user->getUserIdentifier(), $keepCurrentDevice];
    }
}
