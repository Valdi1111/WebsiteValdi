<?php

namespace App\CoreBundle\Model;

/**
 * Encapsulates table query metadata, projection mappings, relationship joins,
 * eager fetch joins, and post-query row transformers.
 */
class TableConfiguration
{
    /**
     * @var null|callable(array<string, mixed>, object): array<string, mixed>
     */
    private $rowTransformer = null;

    /**
     * @param string $rootEntityClass The FQCN of the root entity (e.g. Credential::class)
     * @param string $rootAlias The root alias used in DQL queries (default: 'e')
     * @param array<string, string> $fieldMappings Map of [ 'frontendDataIndex' => 'dql.targetProperty' ]
     * @param array<string, string> $joins Map of [ 'joinAlias' => 'targetRelation' ] (e.g. ['s' => 'e.server'])
     * @param string[] $fetchJoins List of joined aliases to include in the SELECT clause when hydrateObjects is enabled to avoid N+1 queries
     * @param bool $hydrateObjects Whether to hydrate full entity objects instead of flat array scalars (default: false)
     * @param null|callable(array<string, mixed>, object): array<string, mixed> $rowTransformer Callback accepting (array $row, object $entity)
     */
    public function __construct(
        private readonly string $rootEntityClass,
        private readonly string $rootAlias = 'e',
        private readonly array  $fieldMappings = [],
        private readonly array  $joins = [],
        private readonly array  $fetchJoins = [],
        private readonly bool   $hydrateObjects = false,
        ?callable               $rowTransformer = null
    ) {
        $this->rowTransformer = $rowTransformer;
    }

    public function getRootEntityClass(): string
    {
        return $this->rootEntityClass;
    }

    public function getRootAlias(): string
    {
        return $this->rootAlias;
    }

    public function getFieldMappings(): array
    {
        return $this->fieldMappings;
    }

    public function getJoins(): array
    {
        return $this->joins;
    }

    public function getFetchJoins(): array
    {
        return $this->fetchJoins;
    }

    public function isHydrateObjects(): bool
    {
        return $this->hydrateObjects;
    }

    public function getRowTransformer(): ?callable
    {
        return $this->rowTransformer;
    }

    /**
     * Resolves a frontend dataIndex into its corresponding DQL path.
     * Checks explicit fieldMappings first; falls back to camelCase conversion of snake_case identifiers.
     */
    public function resolveDqlPath(string $dataIndex): string
    {
        if (isset($this->fieldMappings[$dataIndex])) {
            return $this->fieldMappings[$dataIndex];
        }

        // Fallback: convert "folder_name" to "e.folderName"
        $camelProperty = lcfirst(str_replace('_', '', ucwords($dataIndex, '_')));
        return "{$this->rootAlias}.$camelProperty";
    }
}