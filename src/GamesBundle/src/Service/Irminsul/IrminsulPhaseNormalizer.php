<?php

namespace App\GamesBundle\Service\Irminsul;

use Psr\Log\LoggerInterface;

/**
 * Turns the entries of an irminsul.gg banner file (banner-characters.json, banner-weapons.json) into phases with
 * explicit times, following the IrminsulTimeRules of the game.
 *
 * Every time becomes [at (Y-m-d H:i:s), offset (hours from UTC, null = local time of the player's server)]. After
 * the rules are applied, a phase that ends before it starts gets one more year on its end (irminsul has written
 * "2026-01-07" for a phase starting in 2026-11); a phase still ending before its start, or lasting more than
 * MAX_PHASE_DAYS, is dropped. Instants are compared with toInstant(), a time without offset being read as UTC.
 *
 * Malformed entries (no integer id, no version, unreadable start or end, no rateUps list) are skipped. Each rewrite
 * is logged at info level, each year fix and each drop at warning level.
 */
class IrminsulPhaseNormalizer
{
    /** Longest believable phase: a longer one is a data error */
    public const int MAX_PHASE_DAYS = 120;

    private const string DATE_TIME_FORMAT = 'Y-m-d H:i:s';

    /** "2026-11-04 11:00:00", optionally followed by "UTC", "UTC+8" or "UTC-5" */
    private const string TIME_PATTERN = '/^(?<at>\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)(?:\s*(?<zone>UTC)(?<offset>[+-]\d{1,2})?)?$/';

    /** Logger of the current normalize() call: the caller's channel when it gives one */
    private ?LoggerInterface $callLogger = null;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<mixed>         $rawEntries Decoded entries of a banner file
     * @param string               $category   Category stored on every phase ("character", "weapon")
     * @param LoggerInterface|null $logger     Channel of the calling game for the rewrites and drops (the
     *                                         default one otherwise), so they land next to its catalog logs
     *
     * @return list<array{id: int, category: string, version: string, title: ?string, start: array{at: string, offset: ?int}, end: array{at: string, offset: ?int}, rate_ups: list<string>}>
     */
    public function normalize(array $rawEntries, string $category, IrminsulTimeRules $rules, ?LoggerInterface $logger = null): array
    {
        $this->callLogger = $logger;
        try {
            return $this->doNormalize($rawEntries, $category, $rules);
        } finally {
            $this->callLogger = null;
        }
    }

    /**
     * @param array<mixed> $rawEntries
     *
     * @return list<array{id: int, category: string, version: string, title: ?string, start: array{at: string, offset: ?int}, end: array{at: string, offset: ?int}, rate_ups: list<string>}>
     */
    private function doNormalize(array $rawEntries, string $category, IrminsulTimeRules $rules): array
    {
        $entries = [];
        foreach ($rawEntries as $index => $rawEntry) {
            $entry = self::parseEntry($rawEntry);
            if ($entry === null) {
                $this->log()->warning('irminsul banner entry skipped: malformed', [
                    'category' => $category,
                    'index' => $index,
                    'id' => is_array($rawEntry) && is_scalar($rawEntry['id'] ?? null) ? $rawEntry['id'] : null,
                    'version' => is_array($rawEntry) && is_scalar($rawEntry['version'] ?? null) ? $rawEntry['version'] : null,
                ]);
                continue;
            }
            $entries[] = $entry;
        }

        // Start dates of the non-first phases, per version group, for the first-phase end rule
        $phaseTwoStartDates = [];
        foreach ($entries as $entry) {
            if (!self::isFirstPhase($entry['version'])) {
                $phaseTwoStartDates[self::versionGroup($entry['version'])][self::datePart($entry['start'])] = true;
            }
        }

        $phases = [];
        foreach ($entries as $entry) {
            $context = ['category' => $category, 'id' => $entry['id'], 'version' => $entry['version']];
            ['start' => $start, 'end' => $end] = $entry;

            if ($rules->phaseTwoStart !== null) {
                $isFirst = self::isFirstPhase($entry['version']);
                if (!$isFirst && $start['offset'] === null) {
                    $start = $this->rewrite($start, self::datePart($start) . ' ' . $rules->phaseTwoStart, 'start', 'phase two start time', $context);
                }
                if ($isFirst && $end['offset'] === null && isset($phaseTwoStartDates[self::versionGroup($entry['version'])][self::datePart($end)])) {
                    $phaseTwoStart = self::parseAt(self::datePart($end) . ' ' . $rules->phaseTwoStart);
                    $end = $this->rewrite($end, $phaseTwoStart->modify('-1 second')->format(self::DATE_TIME_FORMAT), 'end', 'first phase end before phase two start', $context);
                }
            }

            if ($rules->zonelessEndTime !== null && $end['offset'] === null) {
                $end = $this->rewrite($end, self::datePart($end) . ' ' . $rules->zonelessEndTime, 'end', 'fixed end time', $context);
            }

            if ($rules->zonelessOffset !== null) {
                $start['offset'] ??= $rules->zonelessOffset;
                $end['offset'] ??= $rules->zonelessOffset;
            }

            if (self::toInstant($end) < self::toInstant($start)) {
                $fixedEnd = ['at' => self::parseAt($end['at'])->modify('+1 year')->format(self::DATE_TIME_FORMAT), 'offset' => $end['offset']];
                $this->log()->warning('irminsul phase ends before it starts: one year added to its end', $context + [
                    'start' => $start['at'],
                    'from' => $end['at'],
                    'to' => $fixedEnd['at'],
                ]);
                $end = $fixedEnd;
            }

            $startInstant = self::toInstant($start);
            $endInstant = self::toInstant($end);
            if ($endInstant < $startInstant || $endInstant->getTimestamp() - $startInstant->getTimestamp() > self::MAX_PHASE_DAYS * 86400) {
                $this->log()->warning(sprintf('irminsul phase dropped: it ends before it starts or lasts more than %d days', self::MAX_PHASE_DAYS), $context + [
                    'start' => $start['at'],
                    'end' => $end['at'],
                ]);
                continue;
            }

            $phases[] = [
                'id' => $entry['id'],
                'category' => $category,
                'version' => $entry['version'],
                'title' => $entry['title'],
                'start' => $start,
                'end' => $end,
                'rate_ups' => $entry['rate_ups'],
            ];
        }

        return $phases;
    }

    /**
     * Instant of a normalized time: at its offset when it has one, else in $localZone (UTC when none is given).
     *
     * @param array{at: string, offset: ?int} $time
     *
     * @throws \InvalidArgumentException when "at" is not a valid "Y-m-d H:i:s" time
     */
    public static function toInstant(array $time, ?\DateTimeZone $localZone = null): \DateTimeImmutable
    {
        $at = $time['at'];
        if (self::parseAt($at)->format(self::DATE_TIME_FORMAT) !== $at) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid "Y-m-d H:i:s" time.', $at));
        }

        $offset = $time['offset'] ?? null;
        $zone = is_int($offset) ? new \DateTimeZone(sprintf('%+03d:00', $offset)) : ($localZone ?? new \DateTimeZone('UTC'));

        return new \DateTimeImmutable($at, $zone);
    }

    /**
     * @return array{id: int, version: string, title: ?string, start: array{at: string, offset: ?int}, end: array{at: string, offset: ?int}, rate_ups: list<string>}|null
     */
    private static function parseEntry(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $id = $entry['id'] ?? null;
        $version = is_string($entry['version'] ?? null) ? trim($entry['version']) : '';
        $start = self::parseTime($entry['start'] ?? null);
        $end = self::parseTime($entry['end'] ?? null);
        $rateUps = $entry['rateUps'] ?? null;
        if (!is_int($id) || $version === '' || $start === null || $end === null || !is_array($rateUps)) {
            return null;
        }

        $names = [];
        foreach ($rateUps as $name) {
            if (is_string($name) && trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        return [
            'id' => $id,
            'version' => $version,
            'title' => is_string($entry['title'] ?? null) && trim($entry['title']) !== '' ? trim($entry['title']) : null,
            'start' => $start,
            'end' => $end,
            'rate_ups' => array_values(array_unique($names)),
        ];
    }

    /**
     * @return array{at: string, offset: ?int}|null
     */
    private static function parseTime(mixed $value): ?array
    {
        if (!is_string($value) || !preg_match(self::TIME_PATTERN, trim($value), $matches, \PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        $at = (string) $matches['at'];
        $parsed = \DateTimeImmutable::createFromFormat('!' . self::DATE_TIME_FORMAT, $at, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format(self::DATE_TIME_FORMAT) !== $at) {
            return null;
        }

        $offset = null;
        if ($matches['zone'] !== null) {
            $offset = $matches['offset'] !== null ? (int) $matches['offset'] : 0;
            if ($offset < -12 || $offset > 14) {
                return null;
            }
        }

        return ['at' => $at, 'offset' => $offset];
    }

    /**
     * @param array{at: string, offset: ?int} $time
     * @param array<string, mixed>            $context
     *
     * @return array{at: string, offset: ?int}
     */
    private function rewrite(array $time, string $at, string $field, string $rule, array $context): array
    {
        if ($time['at'] === $at) {
            return $time;
        }

        $this->log()->info(sprintf('irminsul phase %s rewritten (%s)', $field, $rule), $context + ['from' => $time['at'], 'to' => $at]);

        return ['at' => $at, 'offset' => $time['offset']];
    }

    /**
     * "6.1.1" and "Luna II.1" open their version, "6.1.2" and "1.6.11" do not.
     */
    private static function isFirstPhase(string $version): bool
    {
        $parts = explode('.', $version);

        return count($parts) > 1 && end($parts) === '1';
    }

    /**
     * Version without its phase component: "6.1" for "6.1.2", "Luna II" for "Luna II.1".
     */
    private static function versionGroup(string $version): string
    {
        $dot = strrpos($version, '.');

        return $dot === false ? $version : substr($version, 0, $dot);
    }

    /**
     * @param array{at: string, offset: ?int} $time
     */
    private static function datePart(array $time): string
    {
        return substr($time['at'], 0, 10);
    }

    private static function parseAt(string $at): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!' . self::DATE_TIME_FORMAT, $at, new \DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid "Y-m-d H:i:s" time.', $at));
        }

        return $parsed;
    }

    private function log(): LoggerInterface
    {
        return $this->callLogger ?? $this->logger;
    }
}
