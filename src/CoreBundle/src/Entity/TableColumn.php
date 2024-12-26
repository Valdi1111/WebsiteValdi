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
    private ?bool $sorter = false;
    #[SerializedName('sortDirections')]
    private ?array $sortDirections = [];
    #[SerializedName('defaultSortOrder')]
    private ?string $defaultSortOrder = null;
    #[SerializedName('filters')]
    private ?array $filters = null;
    #[SerializedName('hidden')]
    private bool $hidden = false;

    public static function builder(string $title, string $dataIndex): static
    {
        return (new TableColumn())->setTitle($title)->setDataIndex($dataIndex);
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDataIndex(): string
    {
        return $this->dataIndex;
    }

    public function setDataIndex(string $dataIndex): static
    {
        $this->dataIndex = $dataIndex;
        return $this;
    }

    public function hasSorter(): ?bool
    {
        return $this->sorter;
    }

    public function setSorter(?bool $sorter): static
    {
        $this->sorter = $sorter;
        return $this;
    }

    public function getSortDirections(): ?array
    {
        return $this->sortDirections;
    }

    public function setSortDirections(?array $sortDirections): static
    {
        $this->sortDirections = $sortDirections;
        return $this;
    }

    public function getDefaultSortOrder(): ?string
    {
        return $this->defaultSortOrder;
    }

    public function setDefaultSortOrder(?string $defaultSortOrder): static
    {
        $this->defaultSortOrder = $defaultSortOrder;
        return $this;
    }

    public function getFilters(): ?array
    {
        return $this->filters;
    }

    public function setFilters(?array $filters): static
    {
        $this->filters = $filters;
        return $this;
    }

    /**
     * @param class-string<UnitEnum> $enumClass
     * @return self
     */
    public function setFiltersFromEnum(string $enumClass): static
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

    public function setHidden(bool $hidden): static
    {
        $this->hidden = $hidden;
        return $this;
    }

}