<?php

namespace App\Tests\GryphlineBundle\Service\Catalog;

use App\GamesBundle\Service\Irminsul\IrminsulClient;
use App\GamesBundle\Service\Irminsul\IrminsulPhaseNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fake irminsul.gg data API (Endfield files) served through MockHttpClient.
 *
 * The default files are tiny and agree with the default AkeDataFixture pools: their featured items are
 * picked so that every banner maps to a pool without any warning (real dates, not the real featured items
 * of 1.0.3). real() serves the real files downloaded on 2026-11-26: banners verbatim, items reduced to the
 * fields the builder reads.
 */
final class EndfieldIrminsulFixture
{
    public const string BASE_URI = 'https://api.irminsul.gg';
    public const string CHARACTERS = 'v2/endfield/characters.json';
    public const string WEAPONS = 'v2/endfield/weapons.json';
    public const string BANNER_CHARACTERS = 'v2/endfield/banner-characters.json';
    public const string BANNER_WEAPONS = 'v2/endfield/banner-weapons.json';

    /** @var list<string> paths requested on the irminsul host */
    public array $requestedPaths = [];

    /** @var array<string, mixed> path => decoded JSON (or raw string); null = 404 */
    public array $files;

    /**
     * @param array<string, mixed> $files path => decoded JSON (or raw string); null = 404
     */
    public function __construct(array $files = [])
    {
        $this->files = array_replace(self::defaultFiles(), $files);
    }

    /**
     * The real files, with the given overrides.
     *
     * @param array<string, mixed> $files path => decoded JSON (or raw string); null = 404
     */
    public static function real(array $files = []): self
    {
        return new self(array_replace(self::realFiles(), $files));
    }

    /**
     * Every file unreadable (irminsul.gg down).
     */
    public static function down(): self
    {
        return new self(array_fill_keys([self::CHARACTERS, self::WEAPONS, self::BANNER_CHARACTERS, self::BANNER_WEAPONS], null));
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            $path = substr($url, strlen(self::BASE_URI . '/'));
            $this->requestedPaths[] = $path;

            $content = str_starts_with($url, self::BASE_URI . '/') ? ($this->files[$path] ?? null) : null;
            if ($content === null) {
                return new MockResponse('<html>Not Found</html>', ['http_code' => 404]);
            }

            return new MockResponse(
                is_string($content) ? $content : json_encode($content, \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        }, self::BASE_URI);
    }

    public function irminsulClient(): IrminsulClient
    {
        return new IrminsulClient($this->client());
    }

    public static function normalizer(?LoggerInterface $logger = null): IrminsulPhaseNormalizer
    {
        return new IrminsulPhaseNormalizer($logger ?? new NullLogger());
    }

    /**
     * characters.json / weapons.json entry reduced to the fields the builder reads.
     *
     * @return array<string, mixed>
     */
    public static function item(int $id, string $name, string $displayName, int $rarity): array
    {
        return ['id' => $id, 'name' => $name, 'displayName' => $displayName, 'rarity' => $rarity];
    }

    /**
     * @param list<string> $rateUps
     *
     * @return array<string, mixed>
     */
    public static function banner(int $id, string $version, string $start, string $end, array $rateUps, ?string $title = null): array
    {
        return array_filter(
            ['id' => $id, 'version' => $version, 'title' => $title, 'start' => $start, 'end' => $end, 'rateUps' => $rateUps],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultFiles(): array
    {
        return [
            self::CHARACTERS => [
                self::item(1005, 'Chen Qianyu', 'Chen Qianyu', 5),
                self::item(1009, 'Ember', 'Ember', 6),
                self::item(1013, 'Gilberta', 'Gilberta', 6),
            ],
            self::WEAPONS => [
                self::item(20011, 'Delivery Guaranteed', 'Delivery Guaranteed', 6),
                self::item(30003, 'Industry 0.1', 'Industry 0.1', 4),
            ],
            self::BANNER_CHARACTERS => [
                // The 5★ is not a featured 6★: it does not count
                self::banner(10002, '1.0.3', '2026-02-24 12:00:00', '2026-03-11 16:59:59', ['Gilberta', 'Chen Qianyu']),
                self::banner(10017, '1.7.2', '2026-11-25 11:00:00 UTC+8', '2026-12-16 11:59:59', ['Gilberta'], 'RE-Factor Headhunting'),
            ],
            self::BANNER_WEAPONS => [
                self::banner(20002, '1.0.3', '2026-02-24 12:00:00', '2026-04-16 16:59:59', ['Delivery Guaranteed', 'Industry 0.1']),
                self::banner(10017, '1.7.2', '2026-11-25 11:00:00 UTC+8', '2026-12-16 11:59:59', ['Delivery Guaranteed'], 'RE-Factor Issue'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function realFiles(): array
    {
        return [
            self::CHARACTERS => [
                self::item(1003, 'Endministrator', 'Endministrator', 6),
                self::item(1004, 'Perlica', 'Perlica', 5),
                self::item(1005, 'Chen Qianyu', 'Chen Qianyu', 5),
                self::item(1006, 'Wulfgard', 'Wulfgard', 5),
                self::item(1007, 'Arclight', 'Arclight', 5),
                self::item(1009, 'Ember', 'Ember', 6),
                self::item(1011, 'Xaihi', 'Xaihi', 5),
                self::item(1012, 'Avywenna', 'Avywenna', 5),
                self::item(1013, 'Gilberta', 'Gilberta', 6),
                self::item(1014, 'Snowshine', 'Snowshine', 5),
                self::item(1015, 'Lifeng', 'Lifeng', 6),
                self::item(1016, 'Laevatain', 'Laevatain', 6),
                self::item(1017, 'Yvonne', 'Yvonne', 6),
                self::item(1018, 'Da Pan', 'Da Pan', 5),
                self::item(1019, 'Akekuri', 'Akekuri', 4),
                self::item(1020, 'Catcher', 'Catcher', 4),
                self::item(1021, 'Estella', 'Estella', 4),
                self::item(1022, 'Fluorite', 'Fluorite', 4),
                self::item(1023, 'Antal', 'Antal', 4),
                self::item(1024, 'Alesh', 'Alesh', 5),
                self::item(1025, 'Ardelia', 'Ardelia', 6),
                self::item(1026, 'Last Rite', 'Last Rite', 6),
                self::item(1029, 'Pogranichnik', 'Pogranichnik', 6),
                self::item(1027, 'Tangtang', 'Tangtang', 6),
                self::item(1028, 'Rossi', 'Rossi', 6),
                self::item(1030, 'Zhuang Fangyi', 'Zhuang Fangyi', 6),
                self::item(1031, 'Mi Fu', 'Mi Fu', 6),
                self::item(1033, 'Camille', 'Camille', 6),
                self::item(1032, 'Arcane', 'Arcane', 6),
                self::item(1035, 'Liino', 'Liino', 6),
                self::item(1034, 'Typhoeus', 'Typhoeus', 6),
                self::item(1038, 'Purrchena', 'Purrchena', 5),
                self::item(1036, 'Si', 'Si', 6),
                self::item(1037, 'Argent Flow', 'Argent Flow', 6),
                self::item(1040, 'Kay', 'Kay', 6),
                self::item(2002, 'Mimino', 'Mimino', 5),
            ],
            self::WEAPONS => [
                self::item(10003, 'Tarr 11', 'Tarr 11', 3),
                self::item(10005, 'Sundering Steel', 'Sundering Steel', 5),
                self::item(10006, 'Forgeborn Scathe', 'Forgeborn Scathe', 6),
                self::item(10007, 'Fortmaker', 'Fortmaker', 5),
                self::item(10008, 'Contingent Measure', 'Contingent Measure', 4),
                self::item(10009, 'Wave Tide', 'Wave Tide', 4),
                self::item(10010, 'Umbral Torch', 'Umbral Torch', 6),
                self::item(10011, 'Rapid Ascent', 'Rapid Ascent', 6),
                self::item(10012, 'Thermite Cutter', 'Thermite Cutter', 6),
                self::item(10013, 'Eminent Repute', 'Eminent Repute', 6),
                self::item(10014, 'White Night Nova', 'White Night Nova', 6),
                self::item(10015, 'Aspirant', 'Aspirant', 5),
                self::item(10016, 'Never Rest', 'Never Rest', 6),
                self::item(10018, 'Twelve Questions', 'Twelve Questions', 5),
                self::item(10019, 'OBJ Edge of Lightness', 'OBJ Edge of Lightness', 5),
                self::item(10020, 'Finchaser 3.0', 'Finchaser 3.0', 5),
                self::item(10021, 'Grand Vision', 'Grand Vision', 6),
                self::item(20001, 'Hypernova Auto', 'Hypernova Auto', 4),
                self::item(20002, 'Jiminy 12', 'Jiminy 12', 3),
                self::item(20003, 'Fluorescent Roc', 'Fluorescent Roc', 4),
                self::item(20004, 'Wild Wanderer', 'Wild Wanderer', 5),
                self::item(20005, 'Stanza of Memorials', 'Stanza of Memorials', 5),
                self::item(20006, 'Opus Etch Figure', 'Opus: Etch Figure', 6),
                self::item(20007, 'Monaihe', 'Monaihe', 5),
                self::item(20008, 'Chivalric Virtues', 'Chivalric Virtues', 6),
                self::item(20009, 'Oblivion', 'Oblivion', 6),
                self::item(20010, 'Detonation Unit', 'Detonation Unit', 6),
                self::item(20011, 'Delivery Guaranteed', 'Delivery Guaranteed', 6),
                self::item(20012, 'Freedom to Proselytize', 'Freedom to Proselytize', 5),
                self::item(20013, 'Dreams of the Starry Beach', 'Dreams of the Starry Beach', 6),
                self::item(20014, 'OBJ Arts Identifier', 'OBJ Arts Identifier', 5),
                self::item(30003, 'Industry 0.1', 'Industry 0.1', 4),
                self::item(30004, 'Exemplar', 'Exemplar', 6),
                self::item(30006, 'Former Finery', 'Former Finery', 6),
                self::item(30007, 'Thunderberge', 'Thunderberge', 6),
                self::item(30008, 'Sundered Prince', 'Sundered Prince', 6),
                self::item(30009, 'Quencher', 'Quencher', 4),
                self::item(30010, 'Darhoff 7', 'Darhoff 7', 3),
                self::item(30011, 'Seeker of Dark Lung', 'Seeker of Dark Lung', 5),
                self::item(30012, 'Finishing Call', 'Finishing Call', 5),
                self::item(30013, 'Khravengger', 'Khravengger', 6),
                self::item(30014, 'Ancient Canal', 'Ancient Canal', 5),
                self::item(30015, 'OBJ Heavy Burden', 'OBJ Heavy Burden', 5),
                self::item(50003, 'Pathfinder\'s Beacon', 'Pathfinder\'s Beacon', 4),
                self::item(50004, 'Chimeric Justice', 'Chimeric Justice', 5),
                self::item(50006, 'Cohesive Traction', 'Cohesive Traction', 5),
                self::item(50008, 'Aggeloslayer', 'Aggeloslayer', 4),
                self::item(50009, 'Opero 77', 'Opero 77', 3),
                self::item(50010, 'Valiant', 'Valiant', 6),
                self::item(50011, 'JET', 'JET', 6),
                self::item(50012, 'Mountain Bearer', 'Mountain Bearer', 6),
                self::item(50013, 'OBJ Razorhorn', 'OBJ Razorhorn', 5),
                self::item(60001, 'Peco 5', 'Peco 5', 3),
                self::item(60002, 'Howling Guard', 'Howling Guard', 4),
                self::item(60003, 'Long Road', 'Long Road', 4),
                self::item(60004, 'Rational Farewell', 'Rational Farewell', 5),
                self::item(60005, 'Navigator', 'Navigator', 6),
                self::item(60006, 'Opus The Living', 'Opus: The Living', 5),
                self::item(60008, 'Wedge', 'Wedge', 6),
                self::item(60009, 'Clannibal', 'Clannibal', 6),
                self::item(60010, 'Artzy Tyrannical', 'Artzy Tyrannical', 6),
                self::item(60012, 'OBJ Velocitous', 'OBJ Velocitous', 5),
                self::item(60007, 'Home Longing', 'Home Longing', 6),
                self::item(10017, 'Glorius Memory', 'Glorius Memory', 6),
                self::item(60011, 'Brigand\'s Calling', 'Brigand\'s Calling', 6),
                self::item(10022, 'Lupine Scarlet', 'Lupine Scarlet', 6),
                self::item(20015, 'Lone Barge', 'Lone Barge', 6),
                self::item(20017, 'Flickers in the Mist', 'Flickers in the Mist', 6),
                self::item(30016, 'Phantom Pain', 'Phantom Pain', 6),
                self::item(30017, 'Amaranthine Tassel', 'Amaranthine Tassel', 6),
                self::item(50007, 'Beacon of Duty', 'Beacon of Duty', 6),
                self::item(50015, 'Blessing of Lustrous Carmine', 'Blessing of Lustrous Carmine', 6),
                self::item(10026, 'Farsight', 'Farsight', 6),
                self::item(20016, 'Type 42 Solemn Phalanx', 'Type 42: Solemn Phalanx', 6),
                self::item(20018, 'Junction Point', 'Junction Point', 6),
                self::item(50016, 'Golden Age', 'Golden Age', 6),
                self::item(50014, 'Bedazzling Night Debut', 'Bedazzling Night Debut', 6),
                self::item(20019, 'Umbra of Frigid Eventide', 'Umbra of Frigid Eventide', 6),
                self::item(20020, 'Suffering\'s End', 'Suffering\'s End', 6),
                self::item(10023, 'Dessert Moment', 'Dessert Moment', 5),
                self::item(30018, 'Jade Vessel', 'Jade Vessel', 6),
                self::item(30019, 'Long Heritage', 'Long Heritage', 6),
                self::item(10024, 'Complexed Industry', 'Complexed Industry', 6),
                self::item(10025, 'Lunar Calling', 'Lunar Calling', 6),
                self::item(50017, 'Sweet Deal', 'Sweet Deal', 6),
                self::item(60013, 'Star of Tomorrow', 'Star of Tomorrow', 5),
                self::item(50018, 'Raid Comet', 'Raid Comet', 6),
            ],
            self::BANNER_CHARACTERS => [
                self::banner(10000, '1.0.1', '2026-01-22 11:00:00 UTC+8', '2026-02-07 11:59:59', ['Laevatain']),
                self::banner(10001, '1.0.2', '2026-02-07 12:00:00', '2026-02-24 11:59:59', ['Gilberta']),
                self::banner(10002, '1.0.3', '2026-02-24 12:00:00', '2026-03-11 16:59:59', ['Yvonne']),
                self::banner(10003, '1.1.1', '2026-03-12 11:00:00 UTC+8', '2026-03-29 11:59:59', ['Tangtang']),
                self::banner(10004, '1.1.2', '2026-03-29 12:00:00', '2026-04-16 16:59:59', ['Rossi']),
                self::banner(10005, '1.2.1', '2026-04-17 11:00:00 UTC+8', '2026-05-22 16:59:59', ['Zhuang Fangyi']),
                self::banner(10006, '1.2.2', '2026-05-14 12:00:00', '2026-06-04 16:59:59', ['Laevatain', 'Gilberta', 'Ardelia', 'Pogranichnik']),
                self::banner(10007, '1.3.1', '2026-06-05 11:00:00 UTC+8', '2026-06-26 11:59:59', ['Mi Fu']),
                self::banner(10008, '1.3.2', '2026-06-26 12:00:00', '2026-07-15 16:59:59', ['Camille']),
                self::banner(10009, '1.4.1', '2026-07-16 11:00:00 UTC+8', '2026-08-09 11:59:59', ['Arcane']),
                self::banner(10010, '1.4.2', '2026-08-09 12:00:00', '2026-09-01 16:59:59', ['Liino']),
                self::banner(10011, '1.5.1', '2026-09-02 11:00:00 UTC+8', '2026-09-30 11:59:59', ['Typhoeus']),
                self::banner(10012, '1.5.2', '2026-09-24 12:00:00', '2026-10-14 16:59:59', ['Yvonne'], 'RE-Factor Headhunting'),
                self::banner(10013, '1.6.1', '2026-10-15 11:00:00 UTC+8', '2026-11-05 11:59:59', ['Si']),
                self::banner(10014, '1.6.11', '2026-10-29 12:00:00', '2026-11-19 16:59:59', ['Tangtang'], 'RE-Factor Headhunting'),
                self::banner(10015, '1.6.2', '2026-11-05 12:00:00', '2026-11-24 16:59:59', ['Argent Flow']),
                self::banner(10016, '1.7.1', '2026-11-25 11:00:00 UTC+8', '2026-12-23 11:59:59', ['Kay']),
                self::banner(10017, '1.7.2', '2026-11-25 11:00:00 UTC+8', '2026-12-16 11:59:59', ['Gilberta'], 'RE-Factor Headhunting'),
                self::banner(10018, '1.7.3', '2026-12-16 12:00:00', '2027-01-06 16:59:59', ['Rossi'], 'RE-Factor Headhunting'),
            ],
            self::BANNER_WEAPONS => [
                self::banner(20000, '1.0.1', '2026-01-22 11:00:00 UTC+8', '2026-03-11 11:59:59', ['Forgeborn Scathe']),
                self::banner(20001, '1.0.2', '2026-02-07 12:00:00', '2026-03-28 11:59:59', ['Delivery Guaranteed']),
                self::banner(20002, '1.0.3', '2026-02-24 12:00:00', '2026-04-16 16:59:59', ['Artzy Tyrannical']),
                self::banner(20003, '1.1.1', '2026-03-12 11:00:00 UTC+8', '2026-05-22 11:59:59', ['Brigand\'s Calling']),
                self::banner(20004, '1.1.2', '2026-03-29 12:00:00', '2026-06-26 16:59:59', ['Lupine Scarlet']),
                self::banner(20005, '1.2.1', '2026-04-17 11:00:00 UTC+8', '2026-07-15 16:59:59', ['Lone Barge']),
                self::banner(20006, '1.2.2', '2026-05-14 12:00:00', '2026-06-04 16:59:59', ['Forgeborn Scathe', 'Delivery Guaranteed']),
                self::banner(20007, '1.3.1', '2026-06-05 11:00:00 UTC+8', '2026-08-09 11:59:59', ['Amaranthine Tassel']),
                self::banner(20008, '1.3.2', '2026-06-26 12:00:00', '2026-09-01 16:59:59', ['Blessing of Lustrous Carmine']),
                self::banner(20009, '1.4.1', '2026-07-16 11:00:00 UTC+8', '2026-09-30 11:59:59', ['Type 42 Solemn Phalanx']),
                self::banner(20010, '1.4.2', '2026-08-09 12:00:00', '2026-11-05 16:59:59', ['Bedazzling Night Debut']),
                self::banner(20011, '1.5.1', '2026-09-02 11:00:00 UTC+8', '2026-11-26 11:59:59', ['Umbra of Frigid Eventide']),
                self::banner(20012, '1.5.2', '2026-09-24 12:00:00', '2026-10-14 16:59:59', ['Artzy Tyrannical'], 'RE-Factor Issue'),
                self::banner(20013, '1.6.1', '2026-10-15 11:00:00 UTC+8', '2026-12-17 11:59:59', ['Jade Vessel']),
                self::banner(20014, '1.6.11', '2026-10-29 12:00:00', '2026-11-19 16:59:59', ['Brigand\'s Calling'], 'RE-Factor Issue'),
                self::banner(20015, '1.6.2', '2026-11-05 12:00:00', '2026-01-07 16:59:59', ['Lunar Calling']),
                self::banner(10016, '1.7.1', '2026-11-25 11:00:00 UTC+8', '2027-01-06 11:59:59', ['Sweet Deal']),
                self::banner(10017, '1.7.2', '2026-11-25 11:00:00 UTC+8', '2026-12-16 11:59:59', ['Delivery Guaranteed'], 'RE-Factor Issue'),
                self::banner(10018, '1.7.3', '2026-12-16 12:00:00', '2027-01-06 16:59:59', ['Lupine Scarlet'], 'RE-Factor Issue'),
            ],
        ];
    }
}
