<?php

namespace App\CoreBundle\Entity;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Attribute\SerializedPath;
use Symfony\Component\Validator\Constraints as Assert;

class TableQueryParameters
{
    #[Assert\Positive]
    #[SerializedPath('[pagination][pageSize]')]
    private int $pageSize = 1;

    #[Assert\Positive]
    #[SerializedPath('[pagination][current]')]
    private int $current = 1;

    #[Assert\PositiveOrZero]
    #[SerializedPath('[pagination][total]')]
    private int $total = 0;

    #[SerializedName('filters')]
    private array $filters = [];

    #[SerializedName('sortOrder')]
    private string $sortOrder = 'ascend';

    #[SerializedName('sortField')]
    private string $sortField = '';

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function setPageSize(int $pageSize): static
    {
        $this->pageSize = $pageSize;
        return $this;
    }

    public function getCurrent(): int
    {
        return $this->current;
    }

    public function setCurrent(int $current): static
    {
        $this->current = $current;
        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): static
    {
        $this->total = $total;
        return $this;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function setFilters(array $filters): static
    {
        $this->filters = $filters;
        return $this;
    }

    public function getSortOrder(): string
    {
        return $this->sortOrder;
    }

    public function setSortOrder(string $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getSortField(): string
    {
        return $this->sortField;
    }

    public function setSortField(string $sortField): static
    {
        $this->sortField = $sortField;
        return $this;
    }

    public function getQueryResultCount(QueryBuilder $qb)
    {
        if ($this->getSortField()) {
            $field = lcfirst(str_replace('_', '', ucwords($this->getSortField(), '_')));
            $qb->addOrderBy("e.$field", $this->sortOrder === 'descend' ? 'DESC' : 'ASC');
        }
        foreach ($this->getFilters() as $filterField => $values) {
            $field = lcfirst(str_replace('_', '', ucwords($filterField, '_')));
            if (empty($values)) {
                continue;
            }
            $qb->andWhere("e.$field IN (:{$field}Values)")
                ->setParameter("{$field}Values", $values);
        }
        return $qb->select('COUNT(e)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getQueryResult(QueryBuilder $qb)
    {
        if ($this->getSortField()) {
            $field = lcfirst(str_replace('_', '', ucwords($this->getSortField(), '_')));
            $qb->addOrderBy("e.$field", $this->sortOrder === 'descend' ? 'DESC' : 'ASC');
        }
        foreach ($this->getFilters() as $filterField => $values) {
            $field = lcfirst(str_replace('_', '', ucwords($filterField, '_')));
            if (empty($values)) {
                continue;
            }
            $qb->andWhere("e.$field IN (:{$field}Values)")
                ->setParameter("{$field}Values", $values);
        }
        return $qb->select('e')
            ->setMaxResults($this->getPageSize())
            ->setFirstResult($this->getPageSize() * ($this->getCurrent() - 1))
            ->getQuery()
            ->getResult();
    }

}