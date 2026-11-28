<?php

namespace App\Tests\CoreBundle;

/**
 * A multipart/form-data request body (a file upload, built with Symfony Mime's FormDataPart) as the callback of
 * MockHttpClient gets it, read back into its parts for the fake APIs and their assertions.
 */
final class MultipartBody
{
    /**
     * The body option of a request as the MockHttpClient callback gets it: a string, or a callback for an iterable body.
     * The callback is read to its end here: the mock response reads it again afterwards, and finds it empty.
     */
    public static function read(mixed $body): string
    {
        if (!$body instanceof \Closure) {
            return (string) $body;
        }

        $read = '';
        while ('' !== $chunk = $body(16372)) {
            $read .= $chunk;
        }

        return $read;
    }

    /**
     * @param array<string, mixed> $options of the request, as the MockHttpClient callback gets them
     */
    public static function contentType(array $options): string
    {
        return $options['normalized_headers']['content-type'][0] ?? '';
    }

    /**
     * @param array<string, mixed> $options of the request, as the MockHttpClient callback gets them
     */
    public static function isMultipart(array $options): bool
    {
        return str_contains(self::contentType($options), 'multipart/form-data');
    }

    /**
     * The parts of a multipart/form-data body, by name and in order: a field as its value, a file as its name, type and
     * bytes.
     *
     * @param string $contentType the Content-Type header, with its boundary
     *
     * @return array<string, string|array{filename: string, contentType: string|null, content: string}>
     */
    public static function parse(string $body, string $contentType): array
    {
        if (preg_match('/boundary=(?|"([^"]+)"|([^\s;]+))/', $contentType, $boundary) !== 1) {
            throw new \InvalidArgumentException(sprintf('Not a multipart content type: "%s".', $contentType));
        }

        $parts = [];
        foreach (explode('--'.$boundary[1], $body) as $part) {
            // What comes before the first boundary, and the "--" after the last one
            if (!str_starts_with($part, "\r\n")) {
                continue;
            }
            [$head, $content] = explode("\r\n\r\n", substr($part, 2), 2) + [1 => ''];
            // The line break before the next boundary belongs to the boundary
            $content = substr($content, 0, -2);
            if (preg_match('/^Content-Disposition: form-data; name="([^"]*)"(?:; filename="([^"]*)")?/mi', $head, $disposition) !== 1) {
                throw new \InvalidArgumentException(sprintf('A part without a form-data name: "%s".', $head));
            }

            if (!isset($disposition[2])) {
                $parts[$disposition[1]] = $content;
                continue;
            }
            $parts[$disposition[1]] = [
                'filename' => $disposition[2],
                'contentType' => preg_match('/^Content-Type: ([^;\r\n]+)/mi', $head, $type) === 1 ? $type[1] : null,
                'content' => $content,
            ];
        }

        return $parts;
    }
}
