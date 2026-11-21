<?php

namespace App\CoreBundle\Model\Encryption;

/**
 * Database column mapped with EncryptedTextType, with the primary key column of its table (unquoted names).
 */
final readonly class EncryptedColumn
{
    public function __construct(
        public string $table,
        public string $column,
        public string $idColumn,
    ) {
    }
}
