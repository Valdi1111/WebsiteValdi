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

        $this->assertStringContainsString('p.origin, p.declaredPity, p.isUp, p.pullNumber', $qb->getDQL());
        $this->assertStringContainsString('p.gachaType IN (:gachaTypes)', $qb->getDQL());
        $this->assertStringEndsWith('ORDER BY p.pulledAt ASC, p.pullNumber ASC, p.pullId ASC', $qb->getDQL());
        $this->assertSame(['301', '400'], $qb->getParameter('gachaTypes')?->getValue());

        $all = $this->capture(static fn (HoyoversePullRepository $repository) => $repository->findProfileRows(new HoyoverseGameProfile()));
        $this->assertStringNotContainsString('gachaType IN', $all->getDQL());
        $this->assertStringEndsWith('ORDER BY p.pulledAt ASC, p.pullNumber ASC, p.pullId ASC', $all->getDQL());
    }

    public function testCompareRowsOrdersLikeTheQuery(): void
    {
        $row = static fn (string $at, ?int $pullNumber, string $pullId): array => [
            'pulledAt' => new \DateTimeImmutable($at, new \DateTimeZone('Europe/Rome')),
            'pullNumber' => $pullNumber,
            'pullId' => $pullId,
        ];
        $rows = [
            'numbered 12' => $row('2026-01-01 10:00:00', 12, 'filler_0193a000-0000-7000-8000-000000000002'),
            'next second' => $row('2026-01-01 10:00:01', null, '1700000000000000001'),
            'manual' => $row('2026-01-01 10:00:00', null, 'manual_0193a000-0000-7000-8000-000000000001'),
            'numbered 9' => $row('2026-01-01 10:00:00', 9, 'manual_0193a000-0000-7000-8000-000000000003'),
            'filler' => $row('2026-01-01 10:00:00', null, 'filler_0193a000-0000-7000-8000-000000000004'),
            'recorded 999' => $row('2026-01-01 10:00:00', null, '999'),
            'recorded 1000' => $row('2026-01-01 10:00:00', null, '1000'),
            'earlier' => $row('2026-01-01 09:59:59', 50, 'manual_0193a000-0000-7000-8000-000000000005'),
        ];

        uasort($rows, HoyoversePullRepository::compareRows(...));

        // Date, then the unnumbered rows (NULL first, like MySQL), then by number, then the pull id as a string
        // ("1000" < "999", digits < "filler_" < "manual_"); a number 0 is not mistaken for NULL
        $this->assertSame(
            ['earlier', 'recorded 1000', 'recorded 999', 'filler', 'manual', 'numbered 9', 'numbered 12', 'next second'],
            array_keys($rows),
        );
        $this->assertSame(-1, HoyoversePullRepository::compareRows($row('2026-01-01 10:00:00', null, 'b'), $row('2026-01-01 10:00:00', 0, 'a')));
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
