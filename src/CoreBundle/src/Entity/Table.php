<?php

namespace App\CoreBundle\Entity;

use App\CoreBundle\Repository\ITableRepository;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;

class Table
{
    /**
     * @var TableColumn[]
     */
    private array $columns = [];

    private TableParameters $defaultParameters;

    /**
     * @param ITableRepository $repository
     * @param TableParameters $queryParameters
     */
    public function __construct(
        private readonly ITableRepository $repository,
        private readonly TableParameters  $queryParameters,
    )
    {
        $this->defaultParameters = new TableParameters();
    }

    /**
     * @return TableColumn[]
     */
    #[SerializedName('columns')]
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getColumn($dataIndex): ?TableColumn
    {
        return array_find($this->getColumns(), fn($c) => $c->getDataIndex() === $dataIndex);
    }

    public function addColumn(string $title, string $dataIndex): TableColumn
    {
        $column = new TableColumn()
            ->setTitle($title)
            ->setDataIndex($dataIndex)
            ->setPropertyPath("e." . lcfirst(str_replace('_', '', ucwords($dataIndex, '_'))));
        $this->columns[] = $column;
        return $column;
    }

    /**
     * @return ITableRepository
     */
    #[Ignore]
    public function getRepository(): ITableRepository
    {
        return $this->repository;
    }

    #[SerializedName('queryParameters')]
    public function getQueryParameters(): TableParameters
    {
        return $this->queryParameters;
    }

    #[SerializedName('defaultParameters')]
    public function getDefaultParameters(): TableParameters
    {
        return $this->defaultParameters;
    }

    #[Ignore]
    public function getParameters(): TableParameters
    {
        return $this->getQueryParameters()->isInitializing() ? $this->getDefaultParameters() : $this->getQueryParameters();
    }

    #[SerializedName('count')]
    public function getCount(): int
    {
        return $this->getRepository()
            ->qbTableResultCount($this)
            ->getQuery()
            ->getSingleScalarResult();
    }

    #[SerializedName('rows')]
    public function getRows(): array
    {
        return $this->getRepository()
            ->qbTableResultRows($this)
            ->getQuery()
            ->getResult();
    }

}