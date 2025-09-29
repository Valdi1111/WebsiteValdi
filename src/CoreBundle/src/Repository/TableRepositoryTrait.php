<?php

namespace App\CoreBundle\Repository;

use App\CoreBundle\Entity\Table;
use App\CoreBundle\Entity\TableColumn;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * @template T of object
 * @mixin ServiceEntityRepository<T>
 */
trait TableRepositoryTrait
{

    public function qbTableResultCount(Table $table): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e');
        $this->applySorter($qb, $table);
        $this->applyFilters($qb, $table);
        return $qb->select('COUNT(e)');
    }

    public function qbTableResultRows(Table $table): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e');
        $this->applySorter($qb, $table);
        $this->applyFilters($qb, $table);
        return $qb
            // TODO da pensare come gestire il select su colonne di entities secondarie
//            ->select(array_map(fn(TableColumn $c) => "{$c->getPropertyPath()} AS {$c->getDataIndex()}", $table->getColumns()))
            ->select('e')
            ->setMaxResults($table->getParameters()->getPageSize())
            ->setFirstResult($table->getParameters()->getPageSize() * ($table->getParameters()->getCurrent() - 1));
    }

    protected function applySorter(QueryBuilder $qb, Table $table): QueryBuilder
    {
        if ($table->getParameters()->getSorterField()) {
            $column = $table->getColumn($table->getParameters()->getSorterField());
            $qb->addOrderBy(
                $column->getPropertyPath(),
                $table->getParameters()->getSorterOrder() === 'descend' ? 'DESC' : 'ASC'
            );
        }
        return $qb;
    }

    protected function applyFilters(QueryBuilder $qb, Table $table): QueryBuilder
    {
        foreach ($table->getParameters()->getFilters() as $dataIndex => $values) {
            $column = $table->getColumn($dataIndex);
            if (empty($values)) {
                continue;
            }
            if ($column->getFilterType() === "enum") {
                $qb->andWhere(
                    $qb->expr()->in($column->getPropertyPath(), $qb->createNamedParameter($values))
                );
            }
            if ($column->getFilterType() === "string") {
                $qb->andWhere(
                    $qb->expr()->orX(
                        ...array_map(
                            fn (string $v) => $qb->expr()->like($column->getPropertyPath(), $qb->createNamedParameter("%$v%")),
                            $values
                        )
                    )
                );
            }
        }
        return $qb;
    }

}