<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Exception\HoyolabUnavailableException;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Normalizer\HoyoverseAccountNormalizer;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Real cookie parsing (serializer honouring the #[SerializedName] cookie keys): capability checks never throw
 * on incomplete cookies.
 */
class HoyolabCookieUtilsServiceTest extends TestCase
{
    private const string BASIC = 'ltoken_v2=v2_lt; ltuid_v2=111; ltmid_v2=mid111';
    private const string REDEMPTION = '; cookie_token_v2=v2_ct; account_id_v2=111; account_mid_v2=mid111';

    private function cookieUtils(?MockHttpClient $httpClient = null): HoyolabCookieUtilsService
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new ObjectNormalizer($metadataFactory, new MetadataAwareNameConverter($metadataFactory))]);

        return new HoyolabCookieUtilsService($httpClient ?? new MockHttpClient(), new NullLogger(), $serializer, $this->createStub(HoyolabUtilsService::class));
    }

    /**
     * @return iterable<string, array{?string, bool, bool}>
     */
    public static function cookieProvider(): iterable
    {
        yield 'basic and redemption tokens' => [self::BASIC . self::REDEMPTION, true, true];
        yield 'basic tokens only' => [self::BASIC, true, false];
        yield 'redemption tokens without account_mid_v2' => [self::BASIC . '; cookie_token_v2=v2_ct; account_id_v2=111', true, false];
        yield 'missing ltoken_v2' => ['ltuid_v2=111; ltmid_v2=mid111' . self::REDEMPTION, false, false];
        yield 'empty' => ['', false, false];
        yield 'null' => [null, false, false];
    }

    #[DataProvider('cookieProvider')]
    public function testCapabilitiesAreReadWithoutThrowing(?string $cookie, bool $parseable, bool $canRedeem): void
    {
        $cookieUtils = $this->cookieUtils();

        $this->assertSame($parseable, $cookieUtils->tryParseCookie($cookie) !== null);
        $this->assertSame($canRedeem, $cookieUtils->canRedeemCodes($cookie));
    }

    public function testAccountWithAnUnparseableCookieIsStillSerialized(): void
    {
        $objectNormalizer = $this->createStub(NormalizerInterface::class);
        $objectNormalizer->method('normalize')->willReturn(['id' => 7]);
        $normalizer = new HoyoverseAccountNormalizer($objectNormalizer, $this->cookieUtils());

        $json = $normalizer->normalize((new HoyoverseAccount())->setCookie('ltuid_v2=111' . self::REDEMPTION . '; stoken_v2=v2_st'));

        $this->assertSame(['id' => 7, 'can_redeem_codes' => false, 'can_auto_renew' => false], $json);
    }

    private static function renewableCookie(): ParsedCookie
    {
        return (new ParsedCookie())->setStokenV2('v2_st')->setLtuidV2('111')->setLtmidV2('mid111')->setLtokenV2('v2_old');
    }

    public function testRenewReplacesTheTokens(): void
    {
        $cookie = self::renewableCookie();
        $response = new MockResponse(json_encode(['retcode' => 0, 'data' => ['tokens' => [
            ['token_type' => 2, 'token' => 'v2_new_lt'],
            ['token_type' => 4, 'token' => 'v2_new_ct'],
        ]]], JSON_THROW_ON_ERROR));

        $this->assertTrue($this->cookieUtils(new MockHttpClient($response))->renew($cookie));
        $this->assertSame('v2_new_lt', $cookie->getLtokenV2());
        $this->assertSame('v2_new_ct', $cookie->getCookieTokenV2());
    }

    public function testRenewRefusedByHoyoverseReturnsFalse(): void
    {
        $response = new MockResponse(json_encode(['retcode' => -100, 'message' => 'Please log in'], JSON_THROW_ON_ERROR));

        $this->assertFalse($this->cookieUtils(new MockHttpClient($response))->renew(self::renewableCookie()));
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function outageProvider(): iterable
    {
        yield 'no connection' => [new MockResponse('', ['error' => 'Could not resolve host: sg-public-api.hoyoverse.com'])];
        yield 'server error' => [new MockResponse('Bad Gateway', ['http_code' => 502])];
        yield 'rate limited' => [new MockResponse('', ['http_code' => 429])];
        yield 'not json (captive portal)' => [new MockResponse('<html>Sign in to the network</html>')];
    }

    #[DataProvider('outageProvider')]
    public function testRenewDuringAnOutageThrowsUnavailable(MockResponse $response): void
    {
        $this->expectException(HoyolabUnavailableException::class);

        $this->cookieUtils(new MockHttpClient($response))->renew(self::renewableCookie());
    }
}
