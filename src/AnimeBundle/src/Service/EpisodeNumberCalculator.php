<?php

namespace App\AnimeBundle\Service;

readonly class EpisodeNumberCalculator
{
    /**
     * Normalize a numeric value to an integer if it has no fractional part,
     * otherwise keep it as a float.
     *
     * @param float|int $number
     * @return int|float
     */
    private function normalizeNumber(float|int $number): int|float
    {
        return (floor($number) == $number) ? (int) $number : (float) $number;
    }

    /**
     * Parse raw episode string into an array of integers and floats.
     * Handles:
     * - "7" -> [7]
     * - "7.5" -> [7.5]
     * - "7-8" -> [7, 8]
     *
     * @param string|null $rawEpisode
     * @return array<int|float>
     */
    public function parseEpisodes(?string $rawEpisode): array
    {
        if ($rawEpisode === null || trim($rawEpisode) === '') {
            return [];
        }

        $rawEpisode = trim($rawEpisode);

        // Multi-episode range like "7-8" or "7_8"
        if (preg_match('/^(\d+(?:\.\d+)?)\s*[-_]\s*(\d+(?:\.\d+)?)$/', $rawEpisode, $matches)) {
            $start = (float) $matches[1];
            $end = (float) $matches[2];

            // If start and end are whole integers in ascending order, expand the sequence (e.g. 7-9 -> [7, 8, 9])
            if (floor($start) == $start && floor($end) == $end && $end >= $start) {
                $numbers = [];
                for ($i = (int) $start; $i <= (int) $end; $i++) {
                    $numbers[] = $i;
                }
                return $numbers;
            }

            // Return start and end normalized if not an expandable integer range
            return [
                $this->normalizeNumber($start),
                $this->normalizeNumber($end),
            ];
        }

        // Single number (integer or decimal like 7.5)
        if (is_numeric($rawEpisode)) {
            return [$this->normalizeNumber((float) $rawEpisode)];
        }

        // Fallback: extract all numbers in the string
        preg_match_all('/\d+(?:\.\d+)?/', $rawEpisode, $matches);
        if (!empty($matches[0])) {
            return array_map(fn($val) => $this->normalizeNumber((float) $val), $matches[0]);
        }

        return [];
    }

    /**
     * Apply offset to an array of episode numbers while keeping whole numbers as integers.
     *
     * @param array<int|float> $numbers
     * @param int $offset
     * @return array<int|float>
     */
    public function applyOffset(array $numbers, int $offset): array
    {
        if ($offset === 0) {
            return $numbers;
        }

        return array_map(fn(int|float $n) => $this->normalizeNumber($n + $offset), $numbers);
    }

    /**
     * Format numbers back into a readable episode string (e.g. "19-20", "19.5", "19").
     *
     * @param array<int|float> $numbers
     * @return string
     */
    public function formatEpisodeString(array $numbers): string
    {
        if (empty($numbers)) {
            return '';
        }

        // Convert individual numbers to their string representation
        $formatted = array_map(fn(int|float $n) => (string) $n, $numbers);

        if (count($formatted) === 1) {
            return $formatted[0];
        }

        // Join multiple numbers using hyphen
        return implode('-', $formatted);
    }

    /**
     * Replace original episode pattern in the filename with the offset-adjusted episode string.
     *
     * @param string|null $originalFile
     * @param string|null $originalEpisode
     * @param string $newEpisode
     * @return string|null
     */
    public function computeFilename(?string $originalFile, ?string $originalEpisode, string $newEpisode): ?string
    {
        if ($originalFile === null || $originalEpisode === null || $originalEpisode === '' || $originalEpisode === $newEpisode) {
            return $originalFile;
        }

        $quotedOriginal = preg_quote($originalEpisode, '/');
        // Match the original episode token with possible zero-padding surrounded by non-digits or boundaries
        $pattern = '/(?<=^|[^0-9])0*' . $quotedOriginal . '(?=[^0-9]|$)/';

        if (preg_match($pattern, $originalFile)) {
            return preg_replace($pattern, $newEpisode, $originalFile, 1);
        }

        return $originalFile;
    }
}
