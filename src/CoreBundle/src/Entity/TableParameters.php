<?php

namespace App\CoreBundle\Entity;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Attribute\SerializedPath;
use Symfony\Component\Validator\Constraints as Assert;

class TableParameters
{
    #[Assert\Positive]
    #[SerializedPath('[pagination][pageSize]')]
    private int $pageSize = 10;

    #[Assert\Positive]
    #[SerializedPath('[pagination][current]')]
    private int $current = 1;

    #[Assert\PositiveOrZero]
    #[SerializedPath('[pagination][total]')]
    private int $total = 0;

    #[SerializedPath('[sorter][field]')]
    private ?string $sorterField = null;

    #[SerializedPath('[sorter][order]')]
    private ?string $sorterOrder = null;

    #[SerializedName('filters')]
    private array $filters = [];

    #[SerializedName('initializing')]
    private bool $initializing = false;

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

    public function getSorterField(): ?string
    {
        return $this->sorterField;
    }

    public function setSorterField(?string $sorterField): static
    {
        $this->sorterField = $sorterField;
        return $this;
    }

    public function getSorterOrder(): ?string
    {
        return $this->sorterOrder;
    }

    public function setSorterOrder(?string $sorterOrder): static
    {
        $this->sorterOrder = $sorterOrder;
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

    public function isInitializing(): bool
    {
        return $this->initializing;
    }

    public function setInitializing(bool $initializing): static
    {
        $this->initializing = $initializing;
        return $this;
    }

}