<?php

namespace App\GamesBundle\Service\Irminsul;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the public irminsul.gg data API (https://api.irminsul.gg/v2/{game}/{file}.json): banner phases and items of
 * the gacha games, used to build the provider banner catalogs. No authentication; the files are a few MB at most and
 * are only read from the console or a worker.
 */
class IrminsulClient
{
    /** Game slugs of the data API */
    public const array GAMES = ['genshin', 'hsr', 'zzz', 'endfield', 'nte'];

    /** Data files read per game */
    public const string FILE_BANNER_CHARACTERS = 'banner-characters';
    public const string FILE_BANNER_WEAPONS = 'banner-weapons';
    public const string FILE_CHARACTERS = 'characters';
    public const string FILE_WEAPONS = 'weapons';

    public const array FILES = [self::FILE_BANNER_CHARACTERS, self::FILE_BANNER_WEAPONS, self::FILE_CHARACTERS, self::FILE_WEAPONS];

    public function __construct(
        #[Target('games.irminsul.client')]
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Decoded entries of a data file.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException for an unknown game or file
     * @throws IrminsulException         when the file cannot be read or is not a non-empty list of objects
     */
    public function fetch(string $game, string $file): array
    {
        if (!in_array($game, self::GAMES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown irminsul.gg game "%s" (known: %s).', $game, implode(', ', self::GAMES)));
        }
        if (!in_array($file, self::FILES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown irminsul.gg file "%s" (known: %s).', $file, implode(', ', self::FILES)));
        }

        $path = sprintf('/v2/%s/%s.json', $game, $file);

        try {
            // getContent() throws on transport errors and on 3xx/4xx/5xx responses
            $content = $this->httpClient->request('GET', $path)->getContent();
        } catch (HttpClientExceptionInterface $e) {
            throw new IrminsulException(sprintf('irminsul.gg file %s could not be read: %s', $path, $e->getMessage()), 0, $e);
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        try {
            $entries = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new IrminsulException(sprintf('irminsul.gg file %s is not valid JSON: %s', $path, $e->getMessage()), 0, $e);
        }

        if (!is_array($entries) || $entries === [] || !array_is_list($entries)) {
            throw new IrminsulException(sprintf('irminsul.gg file %s is not a non-empty list.', $path));
        }
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                throw new IrminsulException(sprintf('irminsul.gg file %s has a non-object entry at index %d.', $path, $index));
            }
        }

        return $entries;
    }
}
