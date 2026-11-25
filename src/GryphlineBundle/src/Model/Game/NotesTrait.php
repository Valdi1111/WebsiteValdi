<?php

namespace App\GryphlineBundle\Model\Game;

use App\GamesBundle\Model\Game\CachedNotesTrait;
use App\GamesBundle\Model\Game\HasNotesInterface;
use App\GamesBundle\Model\GameNotes\GameNotes;
use App\GryphlineBundle\Exception\SkportApiException;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @mixin GameInterface
 * @mixin HasNotesInterface
 */
trait NotesTrait
{
    use CachedNotesTrait;

    #[Required]
    public function setNotesCache(
        #[Target('gryphline.notes.cache')]
        CacheInterface $cache
    ): void
    {
        $this->notesCache = $cache;
    }

    public function getNotes(RuntimeAccountDataInterface $account, bool $fresh = false): GameNotes
    {
        $entry = $this->getCachedNotesEntry($account, $fresh, fn (): array => $this->fetchNotesData($account));

        return $this->withFetchTime($this->getDenormalizer()->denormalize($entry['data'], $this->getNotesClass()), $entry);
    }

    /**
     * @return array{0: array<string, mixed>, 1: bool} the notes payload, and whether it may be cached
     */
    private function fetchNotesData(RuntimeAccountDataInterface $account, bool $retryOn401 = true): array
    {
        $profile = $account->getGameProfile();
        $creds = $this->ensureCredentials($account);

        $path = $this->getUrlNotes();
        $timestamp = (string) time();
        $sign = $this->getSignerService()->generateSignV2($path, $timestamp, $creds->getSalt());

        $headers = [
            'cred' => $creds->getCred(),
            'timestamp' => $timestamp,
            'platform' => '3',
            'vname' => '1.0.0',
            'sk-language' => 'en',
            'sk-game-role' => $profile->getSkGameRole(),
            'sign' => $sign,
        ];

        $response = $this->getSkportClient()->request(Request::METHOD_GET, $path, [
            'headers' => $headers,
        ]);

        if ($response->getStatusCode() === Response::HTTP_UNAUTHORIZED && $retryOn401) {
            $this->getLogger()->warning('SKPort session expired during notes request, forcing OAuth refresh...');
            $this->ensureCredentials($account, force: true);

            return $this->fetchNotesData($account, retryOn401: false);
        }

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            throw new SkportApiException('Notes HTTP error: ' . $response->getStatusCode())
                ->setApiStatusCode($response->getStatusCode());
        }

        $body = $response->toArray(false);
        if (($body['code'] ?? -1) !== 0) {
            throw new SkportApiException('Notes request failed: ' . ($body['message'] ?? 'Error'))
                ->setApiCode($body['code'] ?? null)
                ->setResponseBody($body);
        }

        $data = $body['data']['detail'] ?? $body['data'] ?? null;

        return [is_array($data) ? $data : [], is_array($data)];
    }
}
