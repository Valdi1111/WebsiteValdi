<?php

namespace App\CoreBundle\Service;

use App\CoreBundle\Model\Game\PullItemIconQuery;

class PullItemIconPathCodec
{
    public function encode(string $gameBiz, PullItemIconQuery $query): ?string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $gameBiz)) {
            return null;
        }

        $typeSlug = $this->normalizeTypeSlug($query->itemType);

        if ($query->itemId !== null && $query->itemId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $query->itemId)) {
            return sprintf('%s/id/%s/%s.webp', $gameBiz, $typeSlug, $query->itemId);
        }

        $name = $query->name !== null ? trim($query->name) : '';
        if ($name !== '') {
            $encodedName = rtrim(strtr(base64_encode($name), '+/', '-_'), '=');
            return sprintf('%s/name/%s/%s.webp', $gameBiz, $typeSlug, $encodedName);
        }

        return null;
    }

    /**
     * @return array{gameBiz: string, query: PullItemIconQuery}|null
     */
    public function decode(string $path): ?array
    {
        $normalized = ltrim(trim($path), '/');

        if (preg_match('#^(?P<biz>[a-z0-9_]+)/id/(?P<type>[a-z0-9_-]+)/(?P<id>[A-Za-z0-9_-]+)\.webp$#', $normalized, $matches)) {
            return [
                'gameBiz' => $matches['biz'],
                'query' => new PullItemIconQuery(
                    itemId: $matches['id'],
                    itemType: $matches['type'] === 'any' ? null : $matches['type'],
                ),
            ];
        }

        if (preg_match('#^(?P<biz>[a-z0-9_]+)/name/(?P<type>[a-z0-9_-]+)/(?P<name>[A-Za-z0-9_-]+)\.webp$#', $normalized, $matches)) {
            $b64 = strtr($matches['name'], '-_', '+/');
            $pad = strlen($b64) % 4;
            if ($pad > 0) {
                $b64 .= str_repeat('=', 4 - $pad);
            }
            $decodedName = base64_decode($b64, true);
            if ($decodedName === false || trim($decodedName) === '') {
                return null;
            }

            return [
                'gameBiz' => $matches['biz'],
                'query' => new PullItemIconQuery(
                    name: $decodedName,
                    itemType: $matches['type'] === 'any' ? null : $matches['type'],
                ),
            ];
        }

        return null;
    }

    private function normalizeTypeSlug(?string $itemType): string
    {
        if ($itemType === null || trim($itemType) === '') {
            return 'any';
        }

        $slug = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim($itemType))), '-');

        return $slug !== '' ? $slug : 'any';
    }
}
