<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Ranking;

use App\Application\Ranking\CriteriaRating;
use App\Application\Ranking\DateBasedRanking;
use App\Domain\Enum\RankingAlgorithm;
use App\Domain\Model\RankingCandidate;
use App\Domain\Model\Student;
use App\Domain\ValueObject\RatingWeights;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты DateBasedRanking (B3-01, план v3 §4): алгоритм 1 по дате (R-17),
 * тай-брейк Q-02 (тир → взвешенный скор), id ASC, детерминизм и не-мутация пула.
 *
 * Фикстура весов идентична CriteriaRatingTest (по ключам ADR-002).
 */
final class DateBasedRankingTest extends TestCase
{
    private DateBasedRanking $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new DateBasedRanking(new CriteriaRating(self::ratingWeights()));
    }

    private static function ratingWeights(): RatingWeights
    {
        return new RatingWeights(
            weights: [
                'entrance_exams' => 0.40,
                'gpa_sem12' => 0.25,
                'gpa_basic' => 0.20,
                'entrance_test' => 0.15,
            ],
            bonuses: ['disability' => 10.0],
            tiers: ['paid_over_budget' => true],
            normalization: 'min_max_0_100',
        );
    }

    /**
     * Синтетический кандидат (все метрики внутри границ Q-4/ADR-002).
     */
    private static function candidate(
        int $id,
        string $submittedAt = '2026-09-07 09:00:00',
        int $exams = 150,
        float $gpaSem12 = 3.5,
        float $gpaBasic = 3.5,
        int $test = 50,
        bool $paid = false,
        bool $disabled = false,
    ): RankingCandidate {
        return new RankingCandidate(
            new Student(
                id: $id,
                groupId: 1,
                fullName: 'Студент ' . $id,
                isTargetQuota: false,
                isPaid: $paid,
                isDisabled: $disabled,
                entranceExamsSum: $exams,
                gpaSem12: $gpaSem12,
                gpaBasic: $gpaBasic,
                entranceTestScore: $test,
            ),
            new \DateTimeImmutable($submittedAt),
        );
    }

    /** @param list<RankingCandidate> $candidates
     *  @return list<int> */
    private static function ids(array $candidates): array
    {
        return array_map(
            static fn (RankingCandidate $candidate): int => $candidate->student->id,
            $candidates,
        );
    }

    public function testOrdersBySubmissionDateAsc(): void
    {
        $pool = [
            self::candidate(3, '2026-09-07 09:00:00', exams: 100, gpaSem12: 2.5, gpaBasic: 2.5, test: 30),
            self::candidate(1, '2026-09-07 09:05:00', exams: 300, gpaSem12: 5.0, gpaBasic: 5.0, test: 100),
            self::candidate(2, '2026-09-08 09:00:00'),
        ];

        $sorted = $this->strategy->sort($pool);

        // Дата — главный ключ: ранний submission опережает даже при худших критериях.
        self::assertSame([3, 1, 2], self::ids($sorted));
    }

    public function testTieBreaksByCriteriaDesc(): void
    {
        $pool = [
            self::candidate(10, paid: false, exams: 50, gpaSem12: 2.0, gpaBasic: 2.0, test: 10),
            self::candidate(11, paid: true, exams: 150, gpaSem12: 3.5, gpaBasic: 3.5, test: 50),
            self::candidate(12, paid: false, exams: 300, gpaSem12: 5.0, gpaBasic: 5.0, test: 100),
        ];

        $sorted = $this->strategy->sort($pool);

        // Равные даты → Q-02: тир платности DESC (11), затем взвешенный скор DESC (12, 10).
        self::assertSame([11, 12, 10], self::ids($sorted));
    }

    public function testTieBreaksByStudentIdAsc(): void
    {
        $pool = [
            self::candidate(5),
            self::candidate(2),
            self::candidate(9),
        ];

        $sorted = $this->strategy->sort($pool);

        // Равные дата + критерии (метрики одинаковы) → student.id ASC.
        self::assertSame([2, 5, 9], self::ids($sorted));
    }

    public function testDeterministicAndDoesNotMutatePool(): void
    {
        $pool = [
            self::candidate(3, '2026-09-07 09:00:00'),
            self::candidate(1, '2026-09-07 09:00:00', exams: 250),
            self::candidate(2, '2026-09-08 09:00:00'),
            self::candidate(4, '2026-09-07 09:00:00'),
        ];
        $original = self::ids($pool);

        $first = $this->strategy->sort($pool);
        $second = $this->strategy->sort($pool);

        self::assertSame(self::ids($first), self::ids($second));
        self::assertSame($original, self::ids($pool));
    }

    public function testReturnsFullTotalOrder(): void
    {
        $pool = [
            self::candidate(4, '2026-09-08 09:00:00'),
            self::candidate(1, '2026-09-07 09:00:00'),
            self::candidate(5, '2026-09-07 09:00:00'),
            self::candidate(2, '2026-09-07 09:05:00'),
            self::candidate(3, '2026-09-08 09:00:00'),
        ];

        $sorted = $this->strategy->sort($pool);

        self::assertSame(count($pool), count($sorted));
        self::assertSame(count($pool), count(array_unique(self::ids($sorted))));
        self::assertSame([1, 5, 2, 3, 4], self::ids($sorted));
    }

    public function testAlgorithmReturnsDate(): void
    {
        self::assertSame(RankingAlgorithm::Date, $this->strategy->algorithm());
    }
}