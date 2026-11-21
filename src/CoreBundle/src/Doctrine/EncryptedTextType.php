<?php

namespace App\CoreBundle\Doctrine;

use App\CoreBundle\Exception\DatabaseEncryptionException;
use App\CoreBundle\Service\Encryption\DatabaseCipher;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\TextType;

/**
 * TEXT column whose values are encrypted at rest by DatabaseCipher, for secrets such as cookies and tokens.
 *
 * Entities always see the plain value:
 * - without a key, values are written in plain text as with Types::TEXT, and reading an encrypted value throws;
 *   a value starting with the encrypted prefix is refused on write, since it could not be read back;
 * - with a key, values are written encrypted, and both encrypted values and plain values not converted yet
 *   (see the core:database:encrypt command) are read;
 * - a value that cannot be decrypted (wrong key, altered data) throws, it is never returned as is.
 * null and empty strings are stored unchanged.
 *
 * The SQL declaration is the one of Types::TEXT, so moving a column to this type changes no schema.
 * DBAL types are not services: CoreBundle::boot() sets a resolver returning the configured cipher. Without a
 * resolver (outside the kernel) the type behaves as if no key were configured.
 */
class EncryptedTextType extends TextType
{
    public const string NAME = 'encrypted_text';

    /** @var (\Closure(): DatabaseCipher)|null */
    private static ?\Closure $cipherResolver = null;

    /**
     * @param (\Closure(): DatabaseCipher)|null $resolver Called each time a value needs the cipher
     */
    public static function setCipherResolver(?\Closure $resolver): void
    {
        self::$cipherResolver = $resolver;
    }

    /**
     * Removes the resolver only if it is still $expected: another kernel may have set its own meanwhile.
     */
    public static function clearCipherResolver(\Closure $expected): void
    {
        if (self::$cipherResolver === $expected) {
            self::$cipherResolver = null;
        }
    }

    public function convertToDatabaseValue(#[\SensitiveParameter] mixed $value, AbstractPlatform $platform): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $cipher = self::getCipher();
        if ($cipher->isEnabled()) {
            return $cipher->encrypt($value);
        }

        if (is_string($value) && DatabaseCipher::isEncrypted($value)) {
            throw DatabaseEncryptionException::prefixedPlainValue();
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(#[\SensitiveParameter] mixed $value, AbstractPlatform $platform): mixed
    {
        $value = parent::convertToPHPValue($value, $platform);

        // null, empty string and values written before encryption was enabled
        if (!is_string($value) || !DatabaseCipher::isEncrypted($value)) {
            return $value;
        }

        return self::getCipher()->decrypt($value);
    }

    private static function getCipher(): DatabaseCipher
    {
        return self::$cipherResolver !== null ? (self::$cipherResolver)() : new DatabaseCipher();
    }
}
