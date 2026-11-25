<?php

namespace App\Tests\HoyoverseBundle;

use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use PHPUnit\Framework\MockObject\Stub;

/**
 * For tests that are not about the cookie: the profile handlers read it with tryParseCookie(), which an unconfigured
 * stub answers with null, that is an invalid cookie.
 */
trait CookieUtilsStubTrait
{
    private function parsingCookieUtils(?ParsedCookie $parsedCookie = null): HoyolabCookieUtilsService&Stub
    {
        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);
        $cookieUtils->method('tryParseCookie')->willReturn($parsedCookie ?? new ParsedCookie());

        return $cookieUtils;
    }
}
