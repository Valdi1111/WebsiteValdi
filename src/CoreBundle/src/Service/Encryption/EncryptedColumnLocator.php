<?php

namespace App\CoreBundle\Service\Encryption;

use App\CoreBundle\Doctrine\EncryptedTextType;
use App\CoreBundle\Model\Encryption\EncryptedColumn;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds the columns mapped with EncryptedTextType in every entity, so the conversion command also covers
 * columns added later.
 */
class EncryptedColumnLocator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<EncryptedColumn> Sorted by table and column
     */
    public function findColumns(): array
    {
        $columns = [];

        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }

            foreach ($metadata->fieldMappings as $fieldName => $mapping) {
                // Inherited fields live in the parent table, listed with the parent entity
                if ($mapping->type !== EncryptedTextType::NAME || $metadata->isInheritedField($fieldName)) {
                    continue;
                }

                $column = new EncryptedColumn($metadata->getTableName(), $mapping->columnName, $metadata->getSingleIdentifierColumnName());
                $columns[$column->table . '.' . $column->column] = $column;
            }
        }

        ksort($columns);

        return array_values($columns);
    }
}
