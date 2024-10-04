<?php

namespace App\CoreBundle\Entity;

use Symfony\Component\Serializer\Attribute\SerializedName;
use UnitEnum;

class TableColumn
{
    #[SerializedName('title')]
    private string $title;
    #[SerializedName('dataIndex')]
    private string $dataIndex;
    #[SerializedName('sorter')]
    private bool $sorter = false;
    #[SerializedName('sortDirections')]
    private array $sortDirections = [];
    #[SerializedName('defaultSortOrder')]
    private string $defaultSortOrder = '';
    #[SerializedName('filters')]
    private array $filters = [];
    #[SerializedName('hidden')]
    private bool $hidden = false;

    public static function builder(string $title, string $dataIndex): TableColumn
    {
        return (new TableColumn())->setTitle($title)->setDataIndex($dataIndex);
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): TableColumn
    {
        $this->title = $title;
        return $this;
    }

    public function getDataIndex(): string
    {
        return $this->dataIndex;
    }

    public function setDataIndex(string $dataIndex): TableColumn
    {
        $this->dataIndex = $dataIndex;
        return $this;
    }

    public function isSorter(): bool
    {
        return $this->sorter;
    }

    public function setSorter(bool $sorter): TableColumn
    {
        $this->sorter = $sorter;
        return $this;
    }

    public function getSortDirections(): array
    {
        return $this->sortDirections;
    }

    public function setSortDirections(array $sortDirections): TableColumn
    {
        $this->sortDirections = $sortDirections;
        return $this;
    }

    public function getDefaultSortOrder(): string
    {
        return $this->defaultSortOrder;
    }

    public function setDefaultSortOrder(string $defaultSortOrder): TableColumn
    {
        $this->defaultSortOrder = $defaultSortOrder;
        return $this;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function setFilters(array $filters): TableColumn
    {
        $this->filters = $filters;
        return $this;
    }

    /**
     * @param class-string<UnitEnum> $enumClass
     * @return self
     */
    public function setFiltersFromEnum(string $enumClass): TableColumn
    {
        $this->setFilters(array_map(
            fn($e) => ['text' => str_replace('_', ' ', ucfirst($e->value)), 'value' => $e->value],
            $enumClass::cases()
        ));
        return $this;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setHidden(bool $hidden): TableColumn
    {
        $this->hidden = $hidden;
        return $this;
    }

}