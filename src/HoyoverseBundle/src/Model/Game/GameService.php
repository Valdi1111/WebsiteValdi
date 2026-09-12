<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\CookieUpdateFailedException;
use App\HoyoverseBundle\Model\AccountInfoData;
use App\HoyoverseBundle\Model\ParsedCookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

abstract class GameService implements GameInterface, HasHoyolabCheckInInterface
{
    use HoyolabTrait;
    use HoyolabCheckInTrait;

    /**
     * @deprecated
     */
    public function getWebApiUrl(): string
    {
        return "https://webapi-os.account.hoyoverse.com/Api/fetch_cookie_accountinfo";
    }

    /**
     * @deprecated
     */
    public function updateCookie(ParsedCookie $cookie): AccountInfoData
    {
        try {
            $response = $this->getHoyolabClient()->request(Request::METHOD_GET, $this->getWebApiUrl(), [
                'headers' => [
                    'Cookie' => (string) $cookie,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($statusCode !== Response::HTTP_OK) {
                $this->getLogger()->error("Failed to update cookie", [
                    'status' => $statusCode,
                    'body' => $body,
                ]);

                throw new CookieUpdateFailedException("Failed to update cookie")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            $data = $body['data'] ?? [];
            if (!$data || !($data['status'] ?? null) || !($data['cookie_info'] ?? null)) {
                $this->getLogger()->error("Failed to update cookie", [
                    'status' => $statusCode,
                    'body' => $body,
                ]);

                throw new CookieUpdateFailedException("Failed to update cookie")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            return $this->getDenormalizer()->denormalize($data, AccountInfoData::class);

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during login", [
                'error' => $e->getMessage(),
            ]);

            throw new CookieUpdateFailedException("Exception during cookie update: {$e->getMessage()}");
        }
    }

}