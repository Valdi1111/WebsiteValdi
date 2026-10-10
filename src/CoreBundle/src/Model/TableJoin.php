<?php

namespace App\CoreBundle\Model;

use Doctrine\ORM\Query\Expr\Join;

class TableJoin
{
    public function __construct(
        private readonly string  $join,
        private readonly string  $alias,
        private readonly string  $type = Join::LEFT_JOIN,
        private readonly ?string $conditionType = null,
        private readonly ?string $condition = null,
        private readonly ?string $indexBy = null,
    )
    {
    }

    public static function left(
        string  $join,
        string  $alias,
        ?string $condition = null,
        string  $conditionType = Join::WITH,
        ?string $indexBy = null,
    ): self
    {
        return new self(
            join: $join,
            alias: $alias,
            type: Join::LEFT_JOIN,
            conditionType: $condition !== null ? $conditionType : null,
            condition: $condition,
            indexBy: $indexBy
        );
    }

    public static function inner(
        string  $join,
        string  $alias,
        ?string $condition = null,
        string  $conditionType = Join::WITH,
        ?string $indexBy = null,
    ): self
    {
        return new self(
            join: $join,
            alias: $alias,
            type: Join::INNER_JOIN,
            conditionType: $condition !== null ? $conditionType : null,
            condition: $condition,
            indexBy: $indexBy
        );
    }

    public function getJoin(): string
    {
        return $this->join;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getConditionType(): ?string
    {
        return $this->conditionType;
    }

    public function getCondition(): ?string
    {
        return $this->condition;
    }

    public function getIndexBy(): ?string
    {
        return $this->indexBy;
    }
}
