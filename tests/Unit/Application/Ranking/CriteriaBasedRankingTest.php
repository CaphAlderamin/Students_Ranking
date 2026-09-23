<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Ranking;

use App\Application\Ranking\CriteriaBasedRanking;
use App\Application\Ranking\CriteriaRating;
use App\Application\Ranking\CriteriaRatingInterface;
use App\Domain\Enum\RankingAlgorithm;
use App\Domain\Model\RankingCandidate;
use App\Domain\Model\Student;
use App\Domain\ValueObject\RatingWeights;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты CriteriaBasedRanking (B3-02, план v3 §4): алгоритм 2 по критериям
 * (R-17): тир DESC → взвешенный скор DESC → submittedAt ASC → id ASC; детерминизм,
 * не-мутация пула, веса из config/ranking.php и DoD «без пересчёта» — счётчик
 * вызовов keys() у мока CriteriaRatingInterface (ровно один на sort()).
 *
 * Фикстура весов идентична CriteriaRatingTest/DateBasedRankingTest (ADR-002).
 */
final class CriteriaBasedRankingTest extends TestCase
{
    private CriteriaBasedRanking $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new CriteriaBasedRanking(new CriteriaRating(self::ratingWeights()));
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

    public function testSortsByTierThenScoreDesc(): void
    {
        $pool = [
            self::candidate(1, exams: 300, gpaSem12: 5.0, gpaBasic: 5.0, test: 100, paid: false),
            self::candidate(2, exams: 0, gpaSem12: 2.0, gpaBasic: 2.0, test: 0, paid: true),
        ];

        $sorted = $this->strategy->sort($pool);

        // Тир строгий: платник (2) с худшими критериями опережает бюджетника (1)
        // с лучшими; внутри тира один элемент.
        self::assertSame([2, 1], self::ids($sorted));
    }

    public function testTieBreaksBySubmissionDateAsc(): void
    {
        $pool = [
            self::candidate(1, '2026-09-08 09:00:00'),
            self::candidate(2, '2026-09-07 09:00:00'),
        ];

        $sorted = $this->strategy->sort($pool);

        // Равные тир и скор (метрики идентичны ⇒ одинаковый ключ) → ранний
        // submittedAt первым (тай-брейк Strategy 2), не id.
        self::assertSame([2, 1], self::ids($sorted));
    }

    public function testTieBreaksByStudentIdAsc(): void
    {
        $pool = [
            self::candidate(5),
            self::candidate(2),
            self::candidate(9),
        ];

        $sorted = $this->strategy->sort($pool);

        // Равные тир + скор + дата (метрики идентичны) → student.id ASC.
        self::assertSame([2, 5, 9], self::ids($sorted));
    }

    public function testDeterministicAndDoesNotMutatePool(): void
    {
        $pool = [
            self::candidate(3, exams: 100, gpaSem12: 2.5, gpaBasic: 2.5, test: 30),
            self::candidate(1, '2026-09-07 09:05:00', paid: true),
            self::candidate(2, exams: 300, gpaSem12: 5.0, gpaBasic: 5.0, test: 100),
            self::candidate(4, disabled: true),
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
            self::candidate(4, exams: 150, gpaSem12: 3.5, gpaBasic: 3.5, test: 50),
            self::candidate(1, exams: 0, gpaSem12: 2.0, gpaBasic: 2.0, test: 0, paid: true),
            self::candidate(5, exams: 0, gpaSem12: 2.0, gpaBasic: 2.0, test: 0),
            self::candidate(2, exams: 300, gpaSem12: 5.0, gpaBasic: 5.0, test: 100, paid: true),
            self::candidate(3, exams: 300, gpaSem12: 5.0, gpaBasic: 5.0, test: 100),
        ];

        $sorted = $this->strategy->sort($pool);

        self::assertCount(5, $sorted);
        self::assertCount(5, array_unique(self::ids($sorted)));
        // Платники (2 лучший, 1 худший — тир доминирует над скором), затем
        // бюджетники по скору: 3 (лучший) → 4 (средний) → 5 (худший).
        self::assertSame([2, 1, 3, 4, 5], self::ids($sorted));
    }

    public function testAlgorithmReturnsCriteria(): void
    {
        self::assertSame(RankingAlgorithm::Criteria, $this->strategy->algorithm());
    }

    public function testWeightsFromConfigFileValidate(): void
    {
        /** @var array{weights: array<string, float>, bonuses: array<string, float>, tiers: array<string, bool>, normalization: string} $config */
        $config = require dirname(__DIR__, 4) . '/config/ranking.php';

        // RatingWeights принимает только валидный набор ключей/сумму/тиры —
        // успешное построение и есть проверка валидности файла-источника.
        $weights = new RatingWeights(
            weights: $config['weights'],
            bonuses: $config['bonuses'],
            tiers: $config['tiers'],
            normalization: $config['normalization'],
        );

        // Ручные ожидания значений ADR-002 (норма докомментирована в файле).
        self::assertSame([0.40, 0.25, 0.20, 0.15], array_values($weights->weights()));
        self::assertSame(10.0, $weights->bonuses()['disability']);
        self::assertTrue($weights->tiers()['paid_over_budget']);
        self::assertSame('min_max_0_100', $weights->normalization());
        self::assertEqualsWithDelta(1.0, array_sum($weights->weights()), 0.001);
    }

    public function testCriteriaRatingKeysCalledOnceAndConsumed(): void
    {
        /** @var CriteriaRatingInterface&MockObject $criteriaRating */
        $criteriaRating = $this->createMock(CriteriaRatingInterface::class);

        // Ключи, ПРОТИВОРЕЩАЩИЕ реальности пула (фактически 10 — платник с
        // лучшими метриками): стратегия обязана сортировать ровно по ним.
        $fixedKeys = [
            10 => ['tierPaid' => false, 'score' => 100],
            20 => ['tierPaid' => true, 'score' => 1],
            30 => ['tierPaid' => false, 'score' => 500],
        ];
        $criteriaRating->expects(self::once())
            ->method('keys')
            ->willReturn($fixedKeys);

        $pool = [
            self::candidate(10, '2026-09-09 09:00:00', exams: 300, gpaSem12: 5.0, gpaBasic: 5.0, test: 100, paid: true),
            self::candidate(20, '2026-09-08 09:00:00', exams: 0, gpaSem12: 2.0, gpaBasic: 2.0, test: 0),
            self::candidate(30, '2026-09-07 09:00:00', exams: 0, gpaSem12: 2.0, gpaBasic: 2.0, test: 0),
        ];

        $sorted = (new CriteriaBasedRanking($criteriaRating))->sort($pool);

        // Консистентность потребления: порядок соответствует fixedKeys
        // (тир 20 → скор 500 против 100 → 30/10), а не пересчёту по метрикам
        // (реальный пересчёт дал бы [10, 30, 20]).
        self::assertSame([20, 30, 10], self::ids($sorted));
    }
}
