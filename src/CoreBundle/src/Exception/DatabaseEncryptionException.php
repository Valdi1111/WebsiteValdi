<?php

namespace App\CoreBundle\Exception;

/**
 * A database value cannot be encrypted or decrypted. Messages never contain the values.
 */
class DatabaseEncryptionException extends \RuntimeException
{
    public static function missingKey(): self
    {
        return new self('No database encryption key is configured (DATABASE_ENCRYPTION_KEY is empty or not set): encrypted values cannot be read or written.');
    }

    public static function invalidKey(): self
    {
        return new self('Invalid database encryption key: DATABASE_ENCRYPTION_KEY must be a base64-encoded string that decodes to exactly 32 bytes (generate one with "openssl rand -base64 32").');
    }

    public static function prefixedPlainValue(): self
    {
        return new self('A value starting with "enc:v1:" cannot be stored in an encrypted column while no encryption key is configured: it would be read back as an encrypted value.');
    }

    public static function notEncrypted(): self
    {
        return new self('The value is not an encrypted value.');
    }

    public static function malformedValue(): self
    {
        return new self('Malformed encrypted value.');
    }

    public static function encryptionFailed(): self
    {
        return new self('Failed to encrypt a value.');
    }

    public static function decryptionFailed(): self
    {
        return new self('Failed to decrypt a stored value: wrong encryption key or altered value.');
    }
}
