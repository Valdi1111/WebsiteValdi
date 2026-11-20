<?php

namespace App\GamesBundle\Service;

use App\CoreBundle\Entity\User;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

class AuthSessionStore
{
    private const string CIPHER_ALGO = 'aes-256-gcm';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private readonly string $appSecret,
    ) {
    }

    /**
     * Creates a new multi-step login session in the provided cache pool.
     *
     * @param array<string, mixed> $data
     */
    public function create(CacheItemPoolInterface $pool, User $user, array $data, int $ttl = 600): string
    {
        $sessionId = Uuid::v4()->toRfc4122();
        $data['userId'] = $user->getId();

        $item = $pool->getItem($this->buildKey($sessionId));
        $item->set($data);
        $item->expiresAfter($ttl);
        $pool->save($item);

        return $sessionId;
    }

    /**
     * Updates an existing login session in the provided cache pool.
     *
     * @param array<string, mixed> $data
     */
    public function update(CacheItemPoolInterface $pool, User $user, string $sessionId, array $data, int $ttl = 600): void
    {
        $data['userId'] = $user->getId();

        $item = $pool->getItem($this->buildKey($sessionId));
        $item->set($data);
        $item->expiresAfter($ttl);
        $pool->save($item);
    }

    /**
     * Loads and validates ownership of a login session.
     *
     * @return array<string, mixed>|null Returns null if session does not exist or has expired.
     *
     * @throws AccessDeniedException If the session belongs to another user.
     */
    public function get(CacheItemPoolInterface $pool, User $user, string $sessionId): ?array
    {
        if ($sessionId === '') {
            return null;
        }

        $item = $pool->getItem($this->buildKey($sessionId));
        if (!$item->isHit()) {
            return null;
        }

        $data = $item->get();
        if (!is_array($data)) {
            return null;
        }

        if (($data['userId'] ?? null) !== $user->getId()) {
            throw new AccessDeniedException('You do not have access to this login session.');
        }

        return $data;
    }

    /**
     * Deletes a login session from the cache pool.
     */
    public function delete(CacheItemPoolInterface $pool, string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        $pool->deleteItem($this->buildKey($sessionId));
    }

    /**
     * Symmetrically encrypts a secret string (e.g. temporary password during captcha step)
     * using AES-256-GCM and a key derived from kernel.secret.
     */
    public function encryptSecret(#[\SensitiveParameter] string $plaintext): string
    {
        $key = hash_hmac('sha256', 'core.auth_session.secret', $this->appSecret, true);
        $ivLen = openssl_cipher_iv_length(self::CIPHER_ALGO);
        $iv = random_bytes($ivLen);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Failed to encrypt temporary session secret.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts a secret string previously encrypted via encryptSecret().
     */
    public function decryptSecret(#[\SensitiveParameter] string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        $ivLen = openssl_cipher_iv_length(self::CIPHER_ALGO);

        if ($raw === false || strlen($raw) <= ($ivLen + 16)) {
            throw new \RuntimeException('Invalid encrypted session secret payload.');
        }

        $key = hash_hmac('sha256', 'core.auth_session.secret', $this->appSecret, true);
        $iv = substr($raw, 0, $ivLen);
        $tag = substr($raw, $ivLen, 16);
        $ciphertext = substr($raw, $ivLen + 16);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt temporary session secret.');
        }

        return $plaintext;
    }

    private function buildKey(string $sessionId): string
    {
        return 'auth_session_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    }
}
