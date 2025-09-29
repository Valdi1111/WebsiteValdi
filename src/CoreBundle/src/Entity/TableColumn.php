<?php

namespace App\CoreBundle\Entity;

use BackedEnum;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;

class TableColumn
{
    #[SerializedName('title')]
    private string $title;
    #[SerializedName('dataIndex')]
    private string $dataIndex;
    #[Ignore]
    private string $propertyPath;
    #[SerializedName('valueFormat')]
    private string $valueFormat = "string";
    #[SerializedName('fixed')]
    private ?string $fixed = null;
    #[SerializedName('sorter')]
    private ?bool $sorter = false;
    #[SerializedName('sortDirections')]
    private ?array $sortDirections = [];
    #[SerializedName('defaultSortOrder')]
    private ?string $defaultSortOrder = null;
    #[SerializedName('filterType')]
    private string $filterType = "none";
    #[SerializedName('filters')]
    private ?array $filters = null;
    #[SerializedName('hidden')]
    private bool $hidden = false;

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

    public function getPropertyPath(): string
    {
        return $this->propertyPath;
    }

    public function setPropertyPath(string $propertyPath): static
    {
        $this->propertyPath = $propertyPath;
        return $this;
    }

    public function getValueFormat(): string
    {
        return $this->valueFormat;
    }

    public function setValueFormat(string $valueFormat): static
    {
        $this->valueFormat = $valueFormat;
        return $this;
    }

    public function getFixed(): string
    {
        return $this->fixed;
    }

    public function setFixed(?string $fixed): static
    {
        $this->fixed = $fixed;
        return $this;
    }

    public function setFixedLeft(): static
    {
        $this->setFixed("left");
        return $this;
    }

    public function setFixedRight(): static
    {
        $this->setFixed("right");
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

    public function getFilterType(): ?string
    {
        return $this->filterType;
    }

    public function setFilterType(string $filterType): static
    {
        $this->filterType = $filterType;
        return $this;
    }

    public function setFilterTypeString(): static
    {
        $this->setFilterType("string");
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
     * @param class-string<BackedEnum> $enumClass
     * @return self
     */
    public function setFiltersFromEnum(string $enumClass): static
    {
        $this->setFilterType("enum");
        $this->setFilters(array_map(
            fn(BackedEnum $e) => [
                'text' => str_replace('_', ' ', ucfirst($e->value)),
                'value' => $e->value,
            ],
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