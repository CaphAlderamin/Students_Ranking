<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Enum\AssignmentSource;
use App\Domain\Enum\RankingAlgorithm;
use App\Domain\Model\Assignment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Assignment (B1-07): studentId/moduleId > 0, rank ≥ 1 или null
 * (null — неконкурсные источники free/target_quota).
 */
final class AssignmentTest extends TestCase
{
    public function testValidWithRank(): void
    {
        $assignment = new Assignment(
            1,
            2,
            RankingAlgorithm::Criteria,
            AssignmentSource::Competition,
            5,
        );

        self::assertSame(1, $assignment->studentId);
        self::assertSame(2, $assignment->moduleId);
        self::assertSame(RankingAlgorithm::Criteria, $assignment->algorithm);
        self::assertSame(AssignmentSource::Competition, $assignment->source);
        self::assertSame(5, $assignment->rank);
    }

    public function testNullRankAcceptedForFreeSource(): void
    {
        $assignment = new Assignment(1, 2, RankingAlgorithm::Date, AssignmentSource::Free, null);

        self::assertNull($assignment->rank);
    }

    /**
     * @return iterable<string, array{int, int, ?int}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'studentId = 0' => [0, 2, 1];
        yield 'moduleId = 0' => [1, 0, 1];
        yield 'rank = 0' => [1, 2, 0];
        yield 'rank отрицательный' => [1, 2, -3];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalid(int $studentId, int $moduleId, ?int $rank): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Assignment($studentId, $moduleId, RankingAlgorithm::Date, AssignmentSource::Competition, $rank);
    }
}