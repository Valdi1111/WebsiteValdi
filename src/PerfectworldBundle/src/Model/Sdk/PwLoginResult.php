<?php

namespace App\PerfectworldBundle\Model\Sdk;

/**
 * Credentials returned by a successful Perfect World SDK login.
 */
final readonly class PwLoginResult
{
    public function __construct(
        public string $uid,
        #[\SensitiveParameter]
        public string $token,
        public ?int $loginType = null,
    ) {
    }
}
