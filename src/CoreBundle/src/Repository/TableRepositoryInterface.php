<?php

namespace App\CoreBundle\Repository;

use App\CoreBundle\Model\Table;
use Doctrine\ORM\QueryBuilder;

interface TableRepositoryInterface
{

    public function qbTableResultCount(Table $table): QueryBuilder;

    public function qbTableResultRows(Table $table): QueryBuilder;

}