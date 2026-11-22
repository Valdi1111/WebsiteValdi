<?php

namespace App\Tests\GryphlineBundle\Repository;

use App\GamesBundle\Model\Pull\PullOrigin;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The DQL the repository builds, captured without a database (the stats and dedup lookups must
 * see the new columns and leave manual and filler rows out where they match on name and time).
 */
class GryphlinePullRepositoryTest extends TestCase
{
    /** @var list<array{dql: string, parameters: array<string, mixed>}> */
    private array $queries = [];

    private function repository(): GryphlinePullRepository
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn(new ClassMetadata(GryphlinePull::class));
        $entityManager->method('createQueryBuilder')->willReturnCallback(static fn (): QueryBuilder => new QueryBuilder($entityManager));
        $entityManager->method('createQuery')->willReturnCallback(function (string $dql): Query {
            $query = $this->createStub(Query::class);
            $query->method('setParameters')->willReturnCallback(function (ArrayCollection $parameters) use ($dql, $query): Query {
                $values = [];
                /** @var Parameter $parameter */
                foreach ($parameters as $parameter) {
                    $values[$parameter->getName()] = $parameter->getValue();
                }
                $this->queries[] = ['dql' => $dql, 'parameters' => $values];

                return $query;
            });
            $query->method('setFirstResult')->willReturnSelf();
            $query->method('setMaxResults')->willReturnSelf();
            $query->method('getArrayResult')->willReturn([]);
            $query->method('getResult')->willReturn([]);

            return $query;
        });

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return new GryphlinePullRepository($registry);
    }

    private function profile(): GryphlineGameProfile
    {
        $profile = new GryphlineGameProfile();
        (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, 7);

        return $profile;
    }

    public function testLogicalDedupCountsOnlyRecordedPulls(): void
    {
        $this->repository()->findExistingLogicalPullCounts($this->profile(), []);

        $this->assertStringContainsString('p.origin = :recorded', $this->queries[0]['dql']);
        $this->assertSame(PullOrigin::Recorded, $this->queries[0]['parameters']['recorded']);
    }

    public function testSyntheticReconciliationCandidatesAreOnlyRecordedPulls(): void
    {
        $this->repository()->findSyntheticCandidatesForReconciliation($this->profile(), 'ext_', ['E_CharacterGachaPoolType_Special'], new \DateTimeImmutable(), 'Chen');

        $this->assertStringContainsString('p.origin = :recorded', $this->queries[0]['dql']);
        $this->assertSame(PullOrigin::Recorded, $this->queries[0]['parameters']['recorded']);
    }

    public function testSeqIdLookupsKeepEveryRow(): void
    {
        // "manual_" / "filler_" seq ids never collide with official or imported ones
        $repository = $this->repository();
        $repository->findExistingSeqKeySet($this->profile());

        $this->assertStringNotContainsString('origin', $this->queries[0]['dql']);
    }

    public function testStatsRowsIncludeTheOriginAndTheDeclaredPity(): void
    {
        $repository = $this->repository();
        $repository->findProfileRows($this->profile());
        $repository->findBannerRows($this->profile(), ['E_CharacterGachaPoolType_Special']);

        foreach ($this->queries as $query) {
            $this->assertStringContainsString('p.origin, p.declaredPity', $query['dql']);
            $this->assertStringContainsString('ORDER BY p.pulledAt ASC', $query['dql']);
        }
        $this->assertSame(['E_CharacterGachaPoolType_Special'], $this->queries[1]['parameters']['categories']);
    }

    public function testSuggestionItemsAreDistinctRecordedItemsWithAnId(): void
    {
        $this->repository()->findDistinctRecordedItems('weapon', 6);

        $dql = $this->queries[0]['dql'];
        $this->assertStringContainsString('p.origin = :recorded', $dql);
        $this->assertStringContainsString('p.itemId IS NOT NULL', $dql);
        $this->assertStringContainsString('GROUP BY p.itemId', $dql);
        $this->assertSame(['poolCategory' => 'weapon', 'minRarity' => 6, 'recorded' => PullOrigin::Recorded], $this->queries[0]['parameters']);
    }
}
