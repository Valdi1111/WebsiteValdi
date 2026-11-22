<?php

namespace App\Tests\HoyoverseBundle\Repository;

use App\GamesBundle\Model\Pull\PullOrigin;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Checks the queries the repository builds, without a database: the entity manager captures the
 * query builder and stops at createQuery().
 */
class HoyoversePullRepositoryTest extends TestCase
{
    private ?QueryBuilder $queryBuilder = null;

    private function createRepository(): HoyoversePullRepository
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn(new ClassMetadata(HoyoversePull::class));
        $em->method('createQueryBuilder')->willReturnCallback(fn (): QueryBuilder => $this->queryBuilder = new QueryBuilder($em));
        $em->method('createQuery')->willThrowException(new \RuntimeException('query captured'));

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        return new HoyoversePullRepository($registry);
    }

    private function capture(callable $call): QueryBuilder
    {
        try {
            $call($this->createRepository());
            $this->fail('The query should have been captured');
        } catch (\RuntimeException $e) {
            $this->assertSame('query captured', $e->getMessage());
        }
        $this->assertNotNull($this->queryBuilder);

        return $this->queryBuilder;
    }

    public function testLogicalPullCountsIgnoreManualAndFillerRows(): void
    {
        $qb = $this->capture(static fn (HoyoversePullRepository $repository) => $repository->findExistingLogicalPullCounts(new HoyoverseGameProfile(), []));

        $this->assertStringContainsString('p.origin = :recorded', $qb->getDQL());
        $this->assertSame(PullOrigin::Recorded, $qb->getParameter('recorded')?->getValue());
    }

    public function testBannerRowsSelectTheNewColumnsInTheChronologicalOrder(): void
    {
        $qb = $this->capture(static fn (HoyoversePullRepository $repository) => $repository->findBannerRows(new HoyoverseGameProfile(), ['301', '400']));

        $this->assertStringContainsString('p.origin, p.declaredPity', $qb->getDQL());
        $this->assertStringContainsString('p.gachaType IN (:gachaTypes)', $qb->getDQL());
        $this->assertStringEndsWith('ORDER BY p.pulledAt ASC, p.pullId ASC', $qb->getDQL());
        $this->assertSame(['301', '400'], $qb->getParameter('gachaTypes')?->getValue());

        $all = $this->capture(static fn (HoyoversePullRepository $repository) => $repository->findProfileRows(new HoyoverseGameProfile()));
        $this->assertStringNotContainsString('gachaType IN', $all->getDQL());
    }

    public function testSuggestionQueriesReadRecordedRowsOnly(): void
    {
        foreach ([
            static fn (HoyoversePullRepository $repository) => $repository->findDistinctRecordedSItems(2, 5),
            static fn (HoyoversePullRepository $repository) => $repository->findDistinctRecordedItemTypes(2),
        ] as $call) {
            $qb = $this->capture($call);
            $this->assertStringContainsString('p.origin = :recorded', $qb->getDQL());
            $this->assertSame(PullOrigin::Recorded, $qb->getParameter('recorded')?->getValue());
            $this->assertSame(2, $qb->getParameter('gameId')?->getValue());
        }
    }
}
