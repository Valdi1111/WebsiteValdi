<?php

namespace App\CoreBundle\Model\StandardTable;

/**
 * Contract for repositories capable of executing paginated and filtered table queries.
 */
interface TableRepositoryInterface
{
    public function getTableUnfilteredCount(TableConfiguration $config): int;

    public function getTableCount(TableParameters $params, TableConfiguration $config): int;

    public function getTableRows(TableParameters $params, TableConfiguration $config): array;
}
