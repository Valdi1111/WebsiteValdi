<?php

namespace App\HoyoverseBundle\Service;

class HoyolabUtilsService
{
    public const string DS_SALT = '6s25p5ox5y14umn1p61aqyyvbvvl3lrt';

    public const array TIME_UNITS = [
        'd' => ['h' => 24, 'm' => 1440, 's' => 86400, 'ms' => 86400.0e3],
        'h' => ['m' => 60, 's' => 3600, 'ms' => 3600.0e3],
        'm' => ['s' => 60, 'ms' => 60.0e3],
        's' => ['ms' => 1.0e3],
    ];

    /**
     * Genera la stringa DS (Dynamic Secret) richiesta.
     */
    public function generateDS(): string
    {
        $time = (string) new \DateTime()->getTimestamp();
        $random = $this->randomString();
        $hash = $this->hash("salt=" . self::DS_SALT . "&t={$time}&r={$random}");

        return "{$time},{$random},{$hash}";
    }

    public function hash(string $string): string
    {
        return md5($string);
    }

    public function randomString(int $length = 6): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $charsLength = strlen($chars);
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $charsLength - 1)];
        }

        return $result;
    }

    public function formatTime(float $seconds = 0): string
    {
        $array = [];

        if ($seconds >= self::TIME_UNITS['d']['s']) {
            $days = (int) floor($seconds / self::TIME_UNITS['d']['s']);
            $array[] = "{$days} days";
            $seconds -= ($days * self::TIME_UNITS['d']['s']);
        }
        if ($seconds >= self::TIME_UNITS['h']['s']) {
            $hr = (int) floor($seconds / self::TIME_UNITS['h']['s']);
            $array[] = "{$hr} hr";
            $seconds -= ($hr * self::TIME_UNITS['h']['s']);
        }
        if ($seconds >= self::TIME_UNITS['m']['s']) {
            $min = (int) floor($seconds / self::TIME_UNITS['m']['s']);
            $array[] = "{$min} min";
            $seconds -= ($min * self::TIME_UNITS['m']['s']);
        }
        if ($seconds >= 0 || empty($array)) {
            $array[] = $this->round($seconds, 3) . ' sec';
        }

        return implode(', ', $array);
    }

    public function round(float $number, int $precision = 0): float
    {
        return round($number, $precision);
    }

    public function escapeCharacters(string $string): string
    {
        return preg_replace('/[_[\]()~`>#+\-=|{}.!]/', '\\\\$0', $string);
    }

    public function fieldsBuilder(array $data, array $options = []): array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            $fields[] = [
                'name' => $this->capitalize($key),
                'value' => $value,
                'inline' => $options[$key] ?? true,
            ];
        }

        return $fields;
    }

    public function convertCase(string $text, string $caseFrom, string $caseTo): string
    {
        $words = [];

        if ($caseFrom === 'camel' && $caseTo === 'snake') {
            $words = preg_split('/(?=[A-Z])/', $text, -1, PREG_SPLIT_NO_EMPTY);
        } elseif ($caseFrom === 'snake' && $caseTo === 'camel') {
            $words = explode('_', $text);
        } elseif ($caseFrom === 'kebab' && $caseTo === 'camel') {
            $words = explode('-', $text);
        } elseif ($caseFrom === 'text' && $caseTo === 'camel') {
            $words = explode(' ', $text);
        }

        $words = array_filter($words);

        $result = '';
        if ($caseTo === 'snake') {
            $result = implode('_', array_map(fn($i) => $this->capitalize($i), $words));
        } elseif ($caseTo === 'kebab') {
            $result = implode('-', $words);
        } elseif ($caseTo === 'camel') {
            foreach (array_values($words) as $ind => $word) {
                $result .= ($ind === 0) ? strtolower($word) : $this->capitalize($word);
            }
        }

        return preg_replace('/id$/i', 'ID', $result);
    }

    public function capitalize(string $string): string
    {
        return ucfirst($string);
    }

}