<?php

namespace App\CoreBundle\Repository;

use App\CoreBundle\Entity\Table;
use Doctrine\ORM\QueryBuilder;

interface ITableRepository
{

    public function qbTableResultCount(Table $table): QueryBuilder;

    public function qbTableResultRows(Table $table): QueryBuilder;

}