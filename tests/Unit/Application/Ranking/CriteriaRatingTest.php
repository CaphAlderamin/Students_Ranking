<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Ranking;

use App\Application\Ranking\CriteriaRating;
use App\Domain\Model\RankingCandidate;
use App\Domain\Model\Student;
use App\Domain\ValueObject\RatingWeights;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты CriteriaRating (B3-01, план v3 §4): min-max нормализация 0–100 внутри
 * пула (ADR-002), веса, аддитивный бонус инвалидам, порядок операций integer-масштаба
 * и детерминированное поведение вырожденного диапазона.
 *
 * Фикстура весов — валидный набор по ключам ADR-002: entrance_exams 0.40,
 * gpa_sem12 0.25, gpa_basic 0.20, entrance_test 0.15; бонус disability 10.0.
 */
final class CriteriaRatingTest extends TestCase
{
    private CriteriaRating $rating;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rating = new CriteriaRating(self::ratingWeights());
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

    public function testMinMaxNormalizationKnownPool(): void
    {
        $pool = [
            self::candidate(1, exams: 0, gpaSem12: 2.0, gpaBasic: 2.0, test: 0),
            self::candidate(2, exams: 180, gpaSem12: 3.8, gpaBasic: 3.8, test: 60),
            self::candidate(3, exams: 300, gpaSem12: 5.0, gpaBasic: 5.0, test: 100),
        ];

        $keys = $this->rating->keys($pool);

        // Все компоненты среднего лежат на 60 % диапазона пула → норм. 0/60/100 × Σw=1.
        self::assertSame(0, $keys[1]['score']);
        self::assertSame(600_000, $keys[2]['score']);
        self::assertSame(1_000_000, $keys[3]['score']);
    }

    public function testWeightsApplied(): void
    {
        $pool = [
            self::candidate(1, gpaSem12: 2.0),
            self::candidate(2, gpaSem12: 5.0),
        ];

        $keys = $this->rating->keys($pool);

        // Остальные компоненты вырождены → норм. 100; gpa_sem12: 0 против 100,
        // вклад веса 0.25 = ровно 250 000 баллов.
        self::assertSame(750_000, $keys[1]['score']);
        self::assertSame(1_000_000, $keys[2]['score']);
    }

    public function testDisabilityBonusAdditive(): void
    {
        $pool = [
            self::candidate(1, disabled: false),
            self::candidate(2, disabled: true),
        ];

        $keys = $this->rating->keys($pool);

        // Метрики одинаковы → вырожденные диапазоны → норм. 100; бонус 10.0 ПОСЛЕ взвешивания.
        self::assertSame(1_000_000, $keys[1]['score']);
        self::assertSame(1_100_000, $keys[2]['score']);
    }

    public function testFractionalWeightedScoreRoundingOrder(): void
    {
        $pool = [
            self::candidate(1, exams: 0),
            self::candidate(2, exams: 100),
            self::candidate(3, exams: 300),
        ];

        $keys = $this->rating->keys($pool);

        // entrance_exams 100 → норм. 33.333…; взвешенный балл = 73.333…;
        // масштаб ВНУТРИ round: round(73.333… × 10⁴) = 733333 (масштаб ПОСЛЕ
        // округления дал бы 730000 — гранулярность тай-брейка деградировала бы).
        self::assertSame(600_000, $keys[1]['score']);
        self::assertSame(733_333, $keys[2]['score']);
        self::assertSame(1_000_000, $keys[3]['score']);
    }

    public function testSingleElementPoolNoDivisionByZero(): void
    {
        $keys = $this->rating->keys([self::candidate(7)]);

        self::assertCount(1, $keys);
        self::assertSame(1_000_000, $keys[7]['score']);
        self::assertFalse($keys[7]['tierPaid']);
    }

    public function testTierFromIsPaid(): void
    {
        $keys = $this->rating->keys([
            self::candidate(1, paid: true),
            self::candidate(2, paid: false),
        ]);

        self::assertTrue($keys[1]['tierPaid']);
        self::assertFalse($keys[2]['tierPaid']);
    }
}