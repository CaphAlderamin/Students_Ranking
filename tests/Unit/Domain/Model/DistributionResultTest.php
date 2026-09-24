<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Enum\AssignmentSource;
use App\Domain\Enum\RankingAlgorithm;
use App\Domain\Model\Assignment;
use App\Domain\Model\DistributionResult;
use App\Domain\Model\Student;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты DistributionResult (B1-07): assignments содержат только Assignment,
 * unassigned — только Student (runtime-валидация B1-06); stats — произвольная.
 */
final class DistributionResultTest extends TestCase
{
    private static function assignment(): Assignment
    {
        return new Assignment(1, 2, RankingAlgorithm::Criteria, AssignmentSource::Competition, 1);
    }

    private static function student(): Student
    {
        return new Student(1, 1, 'Студент', false, false, false, 200, 4.0, 4.0, 80);
    }

    public function testEmptyResult(): void
    {
        $result = new DistributionResult([], [], []);

        self::assertSame([], $result->assignments);
        self::assertSame([], $result->unassigned);
        self::assertSame([], $result->stats);
    }

    public function testValidResult(): void
    {
        $assignment = self::assignment();
        $student = self::student();
        $stats = ['module2' => ['filled' => 1, 'sources' => ['competition' => 1]]];

        $result = new DistributionResult([$assignment], [$student], $stats);

        self::assertSame([$assignment], $result->assignments);
        self::assertSame([$student], $result->unassigned);
        self::assertSame($stats, $result->stats);
    }

    public function testNonAssignmentInAssignmentsRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new DistributionResult([self::student()], [], []);
    }

    public function testNonStudentInUnassignedRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new DistributionResult([], [self::assignment()], []);
    }

    public function testScalarValueRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new DistributionResult(['строка'], [], []);
    }
}