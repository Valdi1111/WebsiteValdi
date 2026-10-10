<?php

namespace App\CoreBundle\Model\StandardTable;

use App\CoreBundle\Exception\InvalidTableJoinException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Window;

/**
 * Trait providing dynamic projection, pagination, sorting, relation joins, and multi-condition filtering for DQL tables.
 *
 * @template T of object
 * @mixin ServiceEntityRepository<T>
 */
trait TableRepositoryTrait
{
    public function getTableUnfilteredCount(TableConfiguration $config): int
    {
        $alias = $config->getRootAlias();
        $qb = $this->createQueryBuilder($alias);

        $this->applyConfiguredJoins($qb, $config);
        $this->applyCustomQueryModifier($qb, $config);

        $countExpression = $this->buildCountDistinctExpression($qb, $config);

        return (int) $qb->select($countExpression)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getTableCount(TableParameters $params, TableConfiguration $config): int
    {
        $alias = $config->getRootAlias();
        $qb = $this->createQueryBuilder($alias);

        $this->applyConfiguredJoins($qb, $config);
        $this->applyCustomQueryModifier($qb, $config);
        $this->applyConfiguredFilters($qb, $params, $config);

        $countExpression = $this->buildCountDistinctExpression($qb, $config);

        return (int) $qb->select($countExpression)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Builds a portable DQL COUNT(DISTINCT ...) expression supporting single non-id PKs and composite PKs.
     */
    protected function buildCountDistinctExpression(QueryBuilder $qb, TableConfiguration $config): string
    {
        $alias = $config->getRootAlias();
        $expr = $qb->expr();

        $metadata = $qb->getEntityManager()->getClassMetadata($config->getRootEntityClass());
        $idFields = $metadata->getIdentifierFieldNames();

        // 1. Single primary key (standard, whether named 'id', 'uuid', etc.)
        if (count($idFields) === 1) {
            return (string) $expr->countDistinct("$alias.{$idFields[0]}");
        }

        // 2. Composite primary key: DQL doesn't support COUNT(DISTINCT a, b).
        // Build an interleaved array: ["e.id", "'_'", "e.tracker", ...]
        $concatArgs = [];
        foreach ($idFields as $index => $field) {
            if ($index > 0) {
                $concatArgs[] = "'_'";
            }
            $concatArgs[] = "$alias.$field";
        }

        return (string) $expr->countDistinct($expr->concat(...$concatArgs));
    }

    public function getTableRows(TableParameters $params, TableConfiguration $config): array
    {
        $alias = $config->getRootAlias();
        $qb = $this->createQueryBuilder($alias);

        $this->applyConfiguredJoins($qb, $config);
        $this->applyCustomQueryModifier($qb, $config);
        $this->applyConfiguredFilters($qb, $params, $config);
        $this->applyConfiguredSorter($qb, $params, $config);

        $limit = $params->getPageSize();
        $offset = $limit * ($params->getCurrent() - 1);

        // Path A: Hydrate full entity objects
        if ($config->isHydrateObjects()) {
            $selectAliases = array_merge([$alias], $config->getFetchJoins());
            $qb->select($selectAliases);

            // Separate root entity fields from joined/external scalar fields
            $rootAlias = $config->getRootAlias();
            $externalSelects = [];
            foreach ($config->getFieldMappings() as $dataIndex => $dqlPath) {
                if (!str_starts_with($dqlPath, "$rootAlias.")) {
                    $externalSelects[$dataIndex] = "$dqlPath AS $dataIndex";
                }
            }

            // Add external joined fields as scalar expressions in the query
            foreach ($externalSelects as $expr) {
                $qb->addSelect($expr);
            }

            $hasExternalSelects = !empty($externalSelects);

            // Use OffsetPaginator when fetch joins are configured to prevent duplicated rows and invalid limits
            if (!empty($config->getFetchJoins())) {
                $paginator = new OffsetPaginator(fetchJoinCollection: true);
                $page = $paginator->paginate($qb->getQuery(), new Window($offset, $limit));
                $results = iterator_to_array($page);
            } else {
                $results = $qb
                    ->setFirstResult($offset)
                    ->setMaxResults($limit)
                    ->getQuery()
                    ->getResult();
            }

            $transformer = $config->getRowTransformer();

            return array_map(function (mixed $resultItem) use ($config, $transformer, $hasExternalSelects) {
                $entity = $hasExternalSelects ? $resultItem[0] : $resultItem;
                $extraScalars = $hasExternalSelects ? array_filter($resultItem, 'is_string', ARRAY_FILTER_USE_KEY) : [];

                // Pre-populate array from root entity getters, then merge joined scalar fields
                $row = array_merge(
                    $this->extractMappedFieldsFromEntity($entity, $config),
                    $extraScalars
                );

                if ($transformer) {
                    return $transformer($row, $entity);
                }

                return $row;
            }, $results);
        }

        // Path B: Flat scalar projection across main and joined entities (high performance)
        $selectExpressions = [];
        foreach ($config->getFieldMappings() as $dataIndex => $dqlPath) {
            $selectExpressions[] = "$dqlPath AS $dataIndex";
        }

        if (empty($selectExpressions)) {
            $qb->select($alias);
        } else {
            $qb->select(implode(', ', $selectExpressions));
        }

        $rows = $qb
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        if ($transformer = $config->getRowTransformer()) {
            return array_map(function (array $row) use ($transformer) {
                return $transformer($row, null);
            }, $rows);
        }

        return $rows;
    }

    protected function applyCustomQueryModifier(QueryBuilder $qb, TableConfiguration $config): void
    {
        if ($modifier = $config->getQueryModifier()) {
            $modifier($qb, $config->getRootAlias());
        }
    }

    /**
     * Extracts values from an entity instance according to configured field mappings.
     *
     * @param object $entity
     * @param TableConfiguration $config
     * @return array<string, mixed>
     */
    protected function extractMappedFieldsFromEntity(object $entity, TableConfiguration $config): array
    {
        $row = [];
        $rootAlias = $config->getRootAlias();

        foreach ($config->getFieldMappings() as $dataIndex => $dqlPath) {
            // Check if path belongs to the root alias (e.g. "e.title" or "e.numEpisodes")
            if (str_starts_with($dqlPath, "$rootAlias.")) {
                $prop = substr($dqlPath, strlen($rootAlias) + 1);
                $row[$dataIndex] = $this->readPropertyFromObject($entity, $prop);
            }
        }

        return $row;
    }

    /**
     * Reads a property value from an object via common getter conventions or direct property access.
     */
    protected function readPropertyFromObject(object $object, string $property): mixed
    {
        $camel = ucfirst($property);

        foreach (["get$camel", "is$camel", "has$camel", $property] as $method) {
            if (method_exists($object, $method)) {
                return $object->$method();
            }
        }

        if (property_exists($object, $property)) {
            return $object->$property;
        }

        return null;
    }

    protected function applyConfiguredJoins(QueryBuilder $qb, TableConfiguration $config): void
    {
        foreach ($config->getJoins() as $key => $joinDef) {
            if ($joinDef instanceof TableJoin) {
                $method = match ($joinDef->getType()) {
                    Join::INNER_JOIN => 'innerJoin',
                    Join::LEFT_JOIN => 'leftJoin',
                    default => throw new InvalidTableJoinException("Invalid table join type '{$joinDef->getType()}'"),
                };

                $qb->$method(
                    join: $joinDef->getJoin(),
                    alias: $joinDef->getAlias(),
                    conditionType: $joinDef->getConditionType(),
                    condition: $joinDef->getCondition(),
                    indexBy: $joinDef->getIndexBy()
                );
                continue;
            }

            // Fallback for string format: ['alias' => 'target']
            $joinAlias = (string) $key;
            $joinTarget = (string) $joinDef;

            $qb->leftJoin($joinTarget, $joinAlias);
        }
    }

    protected function applyConfiguredSorter(QueryBuilder $qb, TableParameters $params, TableConfiguration $config): void
    {
        if ($params->getSorterField()) {
            $dqlTarget = $config->resolveDqlPath($params->getSorterField());
            $direction = $params->getSorterOrder() === 'descend' ? 'DESC' : 'ASC';
            $qb->addOrderBy($dqlTarget, $direction);
        }
    }

    protected function applyConfiguredFilters(QueryBuilder $qb, TableParameters $params, TableConfiguration $config): void
    {
        foreach ($params->getFilters() as $field => $conditions) {
            if (empty($conditions)) {
                continue;
            }

            $dqlTarget = $config->resolveDqlPath($field);

            // Normalize single condition object into a condition list
            if (isset($conditions['operator'])) {
                $conditions = [$conditions];
            }

            foreach ($conditions as $rule) {
                $operator = $rule['operator'] ?? 'exact';
                $val = $rule['value'] ?? null;

                if (!in_array($operator, ['null', 'notNull', 'empty', 'notEmpty'], true) && ($val === null || $val === '')) {
                    continue;
                }

                $this->applyFilterCondition($qb, $dqlTarget, $operator, $val, $field);
            }
        }
    }

    protected function applyFilterCondition(QueryBuilder $qb, string $target, string $operator, mixed $val, string $paramKey): void
    {
        $paramName = preg_replace('/[^a-zA-Z0-9]/', '_', $paramKey) . '_' . bin2hex(random_bytes(4));

        match ($operator) {
            'like' => $qb->andWhere(
                $qb->expr()->like($qb->expr()->lower($target), ":$paramName")
            )->setParameter($paramName, '%' . mb_strtolower((string) $val) . '%'),

            'notLike' => $qb->andWhere(
                $qb->expr()->notLike($qb->expr()->lower($target), ":$paramName")
            )->setParameter($paramName, '%' . mb_strtolower((string) $val) . '%'),

            'startsWith' => $qb->andWhere(
                $qb->expr()->like($qb->expr()->lower($target), ":$paramName")
            )->setParameter($paramName, mb_strtolower((string) $val) . '%'),

            'endsWith' => $qb->andWhere(
                $qb->expr()->like($qb->expr()->lower($target), ":$paramName")
            )->setParameter($paramName, '%' . mb_strtolower((string) $val)),

            'exact', 'eq' => $qb->andWhere($qb->expr()->eq($target, ":$paramName"))
                ->setParameter($paramName, $val),

            'neq' => $qb->andWhere($qb->expr()->neq($target, ":$paramName"))
                ->setParameter($paramName, $val),

            'in' => is_array($val) && count($val) > 0
                ? $qb->andWhere($qb->expr()->in($target, ":$paramName"))->setParameter($paramName, $val)
                : null,

            'gt' => $qb->andWhere($qb->expr()->gt($target, ":$paramName"))->setParameter($paramName, $val),
            'gte' => $qb->andWhere($qb->expr()->gte($target, ":$paramName"))->setParameter($paramName, $val),
            'lt' => $qb->andWhere($qb->expr()->lt($target, ":$paramName"))->setParameter($paramName, $val),
            'lte' => $qb->andWhere($qb->expr()->lte($target, ":$paramName"))->setParameter($paramName, $val),

            'between' => is_array($val) && count($val) === 2 ? (function () use ($qb, $target, $val, $paramName) {
                $pMin = $paramName . '_min';
                $pMax = $paramName . '_max';
                $qb->andWhere("$target BETWEEN :$pMin AND :$pMax")
                    ->setParameter($pMin, $val[0])
                    ->setParameter($pMax, $val[1]);
            })() : null,

            'dateExact' => (function () use ($qb, $target, $val, $paramName) {
                $pStart = $paramName . '_start';
                $pEnd = $paramName . '_end';
                $dateBase = is_string($val) && strlen($val) >= 10 ? substr($val, 0, 10) : (string) $val;
                $qb->andWhere("$target >= :$pStart AND $target <= :$pEnd")
                    ->setParameter($pStart, $dateBase . ' 00:00:00')
                    ->setParameter($pEnd, $dateBase . ' 23:59:59');
            })(),

            'dateRange' => is_array($val) && count($val) === 2 ? (function () use ($qb, $target, $val, $paramName) {
                $pFrom = $paramName . '_from';
                $pTo = $paramName . '_to';
                $fromVal = str_contains($val[0], ' ') ? $val[0] : $val[0] . ' 00:00:00';
                $toVal = str_contains($val[1], ' ') ? $val[1] : $val[1] . ' 23:59:59';
                $qb->andWhere("$target >= :$pFrom AND $target <= :$pTo")
                    ->setParameter($pFrom, $fromVal)
                    ->setParameter($pTo, $toVal);
            })() : null,

            'fromDate' => $qb->andWhere($qb->expr()->gte($target, ":$paramName"))
                ->setParameter($paramName, str_contains((string) $val, ' ') ? $val : $val . ' 00:00:00'),

            'toDate' => $qb->andWhere($qb->expr()->lte($target, ":$paramName"))
                ->setParameter($paramName, str_contains((string) $val, ' ') ? $val : $val . ' 23:59:59'),

            'null', 'empty' => $qb->andWhere("$target IS NULL OR $target = ''"),
            'notNull', 'notEmpty' => $qb->andWhere("$target IS NOT NULL AND $target != ''"),
            default => null,
        };
    }
}
