<?php

namespace App\HoyoverseBundle\Service;

use App\CoreBundle\Service\GameUtilsService;

class HoyolabUtilsService extends GameUtilsService
{
    public const string DS_SALT = '6s25p5ox5y14umn1p61aqyyvbvvl3lrt';
    public const string DS_SALT_LK2 = 'rk4xg2hakoi26nljpr099fv9fck1ah10';
    public const string APP_LOGIN_SALT = 'IZPgfb0dRPtBeLuFkdDznSZ6f4wWt6y2';

    /**
     * Generates the required DS (Dynamic Secret) string.
     */
    public function generateDS(): string
    {
        $time = (string) new \DateTime()->getTimestamp();
        $random = $this->randomString();
        $hash = $this->hash("salt=" . self::DS_SALT . "&t={$time}&r={$random}");

        return "{$time},{$random},{$hash}";
    }

    /**
     * Generates DS v2 (Dynamic Secret v2) with LK2 salt for binding/takumi APIs.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|string $body
     */
    public function generateDsV2(array $query = [], array|string $body = '', string $salt = self::DS_SALT_LK2): string
    {
        $time = (string) (new \DateTime())->getTimestamp();
        $random = (string) random_int(100000, 200000);

        $queryString = '';
        if (!empty($query)) {
            ksort($query);
            $queryString = http_build_query($query);
        }

        $bodyString = is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES) : (string) $body;
        $hash = md5("salt={$salt}&t={$time}&r={$random}&b={$bodyString}&q={$queryString}");

        return "{$time},{$random},{$hash}";
    }

    /**
     * Generates the DS header required for HoYoverse App Passport endpoints (appLoginByPassword, verifier).
     *
     * @param array<string, mixed> $body
     */
    public function generateAppLoginDs(array $body, string $salt = self::APP_LOGIN_SALT): string
    {
        $time = (string) time();
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $maxIndex = strlen($alphabet) - 1;
        $random = '';
        for ($i = 0; $i < 6; $i++) {
            $random .= $alphabet[random_int(0, $maxIndex)];
        }

        $bodyString = json_encode($body, JSON_UNESCAPED_SLASHES);
        $hash = md5("salt={$salt}&t={$time}&r={$random}&b={$bodyString}&q=");

        return "{$time},{$random},{$hash}";
    }
}

