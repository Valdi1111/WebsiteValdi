<?php

namespace App\CoreBundle\Service\Encryption;

use App\CoreBundle\Exception\DatabaseEncryptionException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts values stored in encrypted columns (EncryptedTextType) with AES-256-GCM.
 *
 * Encrypted values are "enc:v1:" + base64(nonce | tag | ciphertext). The key is DATABASE_ENCRYPTION_KEY, the base64
 * encoding of 32 random bytes: without it encryption is disabled. A malformed key is reported when the cipher is
 * used, not when it is built, so it only breaks what reads or writes encrypted values.
 *
 * Public because CoreBundle::boot() hands it to EncryptedTextType: DBAL types are not services.
 */
#[Autoconfigure(public: true)]
class DatabaseCipher
{
    public const string PREFIX = 'enc:v1:';

    private const string CIPHER_ALGO = 'aes-256-gcm';
    private const string AAD = 'core.encrypted_text.v1';
    private const int KEY_LENGTH = 32;
    private const int NONCE_LENGTH = 12;
    private const int TAG_LENGTH = 16;

    public function __construct(
        #[Autowire(param: 'core.database_encryption_key')]
        #[\SensitiveParameter]
        private readonly ?string $key = null,
    ) {
    }

    /**
     * Whether a key is configured: new values are then written encrypted. The key itself is validated on use.
     */
    public function isEnabled(): bool
    {
        return $this->key !== null && $this->key !== '';
    }

    /**
     * Whether a stored value has the encrypted format (prefix check only, nothing is decrypted).
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * @throws DatabaseEncryptionException If no key is configured or the key is malformed.
     */
    public function assertUsable(): void
    {
        $this->getKey();
    }

    /**
     * @throws DatabaseEncryptionException If no usable key is configured.
     */
    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $key = $this->getKey();
        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::AAD,
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw DatabaseEncryptionException::encryptionFailed();
        }

        return self::PREFIX . base64_encode($nonce . $tag . $ciphertext);
    }

    /**
     * Decrypts a value produced by encrypt(). Never returns a value that failed authentication.
     *
     * @throws DatabaseEncryptionException If the value is not encrypted or malformed, no usable key is configured,
     *                                       or the key is wrong / the value was altered.
     */
    public function decrypt(#[\SensitiveParameter] string $value): string
    {
        if (!self::isEncrypted($value)) {
            throw DatabaseEncryptionException::notEncrypted();
        }

        $key = $this->getKey();
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) < self::NONCE_LENGTH + self::TAG_LENGTH) {
            throw DatabaseEncryptionException::malformedValue();
        }

        $plaintext = openssl_decrypt(
            substr($raw, self::NONCE_LENGTH + self::TAG_LENGTH),
            self::CIPHER_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE_LENGTH),
            substr($raw, self::NONCE_LENGTH, self::TAG_LENGTH),
            self::AAD
        );

        if ($plaintext === false) {
            throw DatabaseEncryptionException::decryptionFailed();
        }

        return $plaintext;
    }

    private function getKey(): string
    {
        if ($this->key === null || $this->key === '') {
            throw DatabaseEncryptionException::missingKey();
        }

        $key = base64_decode($this->key, true);
        if ($key === false || strlen($key) !== self::KEY_LENGTH) {
            throw DatabaseEncryptionException::invalidKey();
        }

        return $key;
    }
}
