<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Model\RankingCandidate;
use App\Domain\Model\Student;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тест RankingCandidate (B3-01, план v3 §4, ADR-007): DTO пула ранжирования
 * возвращает student и момент подачи заявки (типы дают защиту).
 */
final class RankingCandidateTest extends TestCase
{
    public function testConstructAndRead(): void
    {
        $student = new Student(
            id: 1,
            groupId: 1,
            fullName: 'Тест Тестов',
            isTargetQuota: false,
            isPaid: false,
            isDisabled: false,
            entranceExamsSum: 150,
            gpaSem12: 3.5,
            gpaBasic: 3.5,
            entranceTestScore: 50,
        );
        $submittedAt = new \DateTimeImmutable('2026-09-07 09:00:00');

        $candidate = new RankingCandidate($student, $submittedAt);

        self::assertSame($student, $candidate->student);
        self::assertSame($submittedAt, $candidate->submittedAt);
    }
}