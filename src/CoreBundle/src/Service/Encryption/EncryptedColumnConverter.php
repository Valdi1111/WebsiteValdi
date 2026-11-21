<?php

namespace App\CoreBundle\Service\Encryption;

use App\CoreBundle\Exception\DatabaseEncryptionException;
use App\CoreBundle\Model\Encryption\EncryptedColumn;
use App\CoreBundle\Model\Encryption\EncryptedColumnStats;
use Doctrine\DBAL\Connection;

/**
 * Converts the stored values of an encrypted column between plain text and the encrypted format.
 *
 * Works on raw values with SQL (entities would decrypt them through EncryptedTextType). Values are never logged
 * or returned, only counted. The conditional UPDATE compares values as binary strings (MySQL syntax, like the
 * migrations): the default collations ignore case and accents.
 */
class EncryptedColumnConverter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DatabaseCipher $cipher,
    ) {
    }

    /**
     * Counts plain and encrypted values without writing. When the configured key is usable, encrypted values are
     * also test-decrypted, so a wrong key shows up as unreadable values before anything is converted.
     */
    public function scan(EncryptedColumn $column): EncryptedColumnStats
    {
        try {
            $this->cipher->assertUsable();
            $unreadable = 0;
        } catch (DatabaseEncryptionException) {
            $unreadable = null;
        }

        $plaintext = $encrypted = 0;
        foreach ($this->fetchValues($column) as [, $value]) {
            if (!DatabaseCipher::isEncrypted($value)) {
                ++$plaintext;
                continue;
            }

            ++$encrypted;
            if ($unreadable !== null && !$this->isDecryptable($value)) {
                ++$unreadable;
            }
        }

        return new EncryptedColumnStats($column, $plaintext, $encrypted, $unreadable);
    }

    /**
     * Encrypts the plain values of a column, or decrypts the encrypted ones with $decrypt.
     *
     * Values already in the target format are left alone, so a second run changes nothing. A row is updated only
     * if it still holds the value read: a worker may have stored a new value meanwhile, and such rows are
     * counted as skipped. With $decrypt, values that cannot be decrypted are left untouched and counted as unreadable,
     * and values whose plain text starts with the encrypted prefix stay encrypted (counted as kept encrypted): stored
     * in plain text they would be taken for encrypted values and could not be read back.
     *
     * @throws DatabaseEncryptionException If no usable key is configured (nothing is read nor written).
     */
    public function convert(EncryptedColumn $column, bool $decrypt): EncryptedColumnStats
    {
        $this->cipher->assertUsable();

        $platform = $this->connection->getDatabasePlatform();
        $sql = sprintf(
            'UPDATE %1$s SET %2$s = ? WHERE %3$s = ? AND CAST(%2$s AS BINARY) = CAST(? AS BINARY)',
            $platform->quoteSingleIdentifier($column->table),
            $platform->quoteSingleIdentifier($column->column),
            $platform->quoteSingleIdentifier($column->idColumn),
        );

        $plaintext = $encrypted = $unreadable = $converted = $skipped = $keptEncrypted = 0;
        foreach ($this->fetchValues($column) as [$id, $value]) {
            $isEncrypted = DatabaseCipher::isEncrypted($value);
            if ($isEncrypted) {
                ++$encrypted;
            } else {
                ++$plaintext;
            }

            // Already in the target format
            if ($isEncrypted !== $decrypt) {
                continue;
            }

            if ($decrypt) {
                try {
                    $newValue = $this->cipher->decrypt($value);
                } catch (DatabaseEncryptionException) {
                    ++$unreadable;
                    continue;
                }

                if (DatabaseCipher::isEncrypted($newValue)) {
                    ++$keptEncrypted;
                    continue;
                }
            } else {
                $newValue = $this->cipher->encrypt($value);
            }

            if ((int) $this->connection->executeStatement($sql, [$newValue, $id, $value]) > 0) {
                ++$converted;
            } else {
                ++$skipped;
            }
        }

        return new EncryptedColumnStats($column, $plaintext, $encrypted, $decrypt ? $unreadable : null, $converted, $skipped, $keptEncrypted);
    }

    /**
     * @return list<array{mixed, string}> Primary key and value of every non-empty value
     */
    private function fetchValues(EncryptedColumn $column): array
    {
        $platform = $this->connection->getDatabasePlatform();
        $id = $platform->quoteSingleIdentifier($column->idColumn);
        $value = $platform->quoteSingleIdentifier($column->column);

        $rows = $this->connection->fetchAllNumeric(sprintf(
            'SELECT %1$s, %2$s FROM %3$s WHERE %2$s IS NOT NULL ORDER BY %1$s',
            $id,
            $value,
            $platform->quoteSingleIdentifier($column->table),
        ));

        $values = [];
        foreach ($rows as [$rowId, $rowValue]) {
            if (is_string($rowValue) && $rowValue !== '') {
                $values[] = [$rowId, $rowValue];
            }
        }

        return $values;
    }

    private function isDecryptable(string $value): bool
    {
        try {
            $this->cipher->decrypt($value);

            return true;
        } catch (DatabaseEncryptionException) {
            return false;
        }
    }
}
