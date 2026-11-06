<?php

namespace App\GryphlineBundle\Service;

class SkportSignerService
{
    private const array V2_PATTERNS = [
        '/binding',
        '/card/detail',
        '/wiki/',
        '/enums',
        '/v2/',
        'attendance',
    ];

    public function generateSignV1(string $timestamp, string $cred): string
    {
        return md5(sprintf('timestamp=%s&cred=%s', $timestamp, $cred));
    }

    public function generateSignV2(
        string $path,
        string $timestamp,
        string $salt,
        string $platform = '3',
        string $vName = '1.0.0'
    ): string {
        $headerJson = json_encode([
            'platform' => $platform,
            'timestamp' => $timestamp,
            'dId' => '',
            'vName' => $vName,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $payload = $path . $timestamp . $headerJson;
        $hmac = hash_hmac('sha256', $payload, $salt);

        return md5($hmac);
    }

    public function requiresV2Sign(string $path): bool
    {
        foreach (self::V2_PATTERNS as $pattern) {
            if (str_contains($path, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
