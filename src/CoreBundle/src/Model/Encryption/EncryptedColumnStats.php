<?php

namespace App\CoreBundle\Model\Encryption;

/**
 * Counts for one encrypted column: values found (before any conversion) and rows converted.
 * Only non-empty values are counted.
 */
final readonly class EncryptedColumnStats
{
    /**
     * @param int|null $unreadable    Encrypted values the configured key cannot decrypt (null = not checked)
     * @param int      $skipped       Rows left untouched because their value changed after being read
     * @param int      $keptEncrypted With decrypt: values left encrypted because their plain text starts with the
     *                                encrypted prefix, so stored in plain text it could not be read back
     */
    public function __construct(
        public EncryptedColumn $column,
        public int $plaintext,
        public int $encrypted,
        public ?int $unreadable = null,
        public int $converted = 0,
        public int $skipped = 0,
        public int $keptEncrypted = 0,
    ) {
    }
}
