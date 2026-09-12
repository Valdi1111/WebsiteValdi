<?php

namespace App\HoyoverseBundle\Service;

use App\HoyoverseBundle\Exception\ConfigurationException;
use App\HoyoverseBundle\Model\ParsedCookie;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class HoyolabCookieUtilsService
{

    public function __construct(
        private readonly DenormalizerInterface $denormalizer
    )
    {
    }

    /**
     * Effettua il parsing di una stringa di cookie HoYoLAB, validando i campi obbligatori
     * e restituendo un'istanza di ParsedCookieResult.
     *
     * @throws ConfigurationException
     */
    public function parseCookie(string $cookieString): ParsedCookie
    {
        $cookieMap = $this->toMap($cookieString);

        $cookie = $this->denormalizer->denormalize($cookieMap, ParsedCookie::class);
        if (!$cookie->isValid()) {
            throw new ConfigurationException("No ltoken_v2, ltuid_v2, or ltmid_v2 found in cookie: $cookieString");
        }
        return $cookie;
    }

    /**
     * Metodo generico per filtrare/manipolare una stringa di cookie con whitelist o blacklist.
     *
     * @param string $cookieString
     * @param string $separator
     * @param array $whitelist
     * @param array $blacklist
     * @return string
     */
    public function filterCookie(string $cookieString, string $separator = ';', array $whitelist = [], array $blacklist = []): string
    {
        if (!empty($whitelist)) {
            $filtered = array_filter(
                $this->toMap($cookieString, $separator),
                static fn(string $key): bool => in_array($key, $whitelist, true),
                ARRAY_FILTER_USE_KEY
            );
            return http_build_query($filtered, '', "$separator ");
        }

        if (!empty($blacklist)) {
            $filtered = array_filter(
                $this->toMap($cookieString, $separator),
                static fn(string $key): bool => !in_array($key, $blacklist, true),
                ARRAY_FILTER_USE_KEY
            );
            return http_build_query($filtered, '', "$separator ");
        }

        return $cookieString;
    }

    /**
     * Converte una stringa di cookie delimitata in un array associativo chiave => valore.
     *
     * @return array<string, string>
     */
    public function toMap(string $cookieString, string $separator = ';'): array
    {
        $map = [];
        $parts = explode($separator, $cookieString);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || !str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);
            $map[trim($key)] = trim($value);
        }

        return $map;
    }
}