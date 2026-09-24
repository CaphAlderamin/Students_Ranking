<?php

declare(strict_types=1);

namespace App\Application\Ranking;

use App\Domain\Model\RankingCandidate;
use App\Domain\Model\Student;
use App\Domain\ValueObject\RatingWeights;

/**
 * Критериальный ключ ранжирования (ADR-002, R-15, R-16).
 *
 * Вычисляет для пула кандидатов полный ключ стратегии критериев «тир платности →
 * взвешенный нормализованный балл» (Q-02: рейтинг по критериям DESC). Используется
 * как тай-брейк стратегией по дате (Q-02) и как основной ключ CriteriaBasedRanking
 * (B3-02, «Меняет» — без дублирования расчёта). Контракт вынесен в
 * CriteriaRatingInterface (B3-02: мокабельность для теста DoD), логика не менялась.
 *
 * Нормализация min-max 0–100 выполняется ВНУТРИ пула: значение каждого компонента
 * линейно проецируется на [0; 100]; при вырожденном диапазоне (max === min, включая
 * пул из одного кандидата) нормализованное значение = 100 (детерминированный предел).
 * Бонус инвалидам (R-16/ADR-002) добавляется ПОСЛЕ взвешивания. Взвешенный балл
 * масштабируется в int ДО округления: `score = (int) round((Σ norm_i × w_i + bonus) × 10⁴)`
 * — дробная гранулярность тай-брейка сохраняется (порядок операций фиксирован).
 */
final readonly class CriteriaRating implements CriteriaRatingInterface
{
    /** Компоненты взвешенного балла (ключи синхронны с RatingWeights, ADR-002). */
    private const array COMPONENTS = [
        'entrance_exams',
        'gpa_sem12',
        'gpa_basic',
        'entrance_test',
    ];

    /** Множитель масштаба балла: стабильность сравнений float → int. */
    private const int SCALE = 10_000;

    /**
     * Критериальный ключ — тир платности → взвешенный нормализованный балл (ADR-002, R-15, R-16).
     *
     * @param RatingWeights $ratingWeights веса компонентов и настройки (config/ranking.php)
     */
    public function __construct(private RatingWeights $ratingWeights)
    {
    }

    /**
     * Критериальные ключи по каждому кандидату пула (CriteriaRatingInterface).
     *
     * @param list<RankingCandidate> $pool пул кандидатов
     *
     * @return array<int, array{tierPaid: bool, score: int}> ключ по studentId
     */
    public function keys(array $pool): array
    {
        if ($pool === []) {
            return [];
        }

        $mins = array_fill_keys(self::COMPONENTS, INF);
        $maxs = array_fill_keys(self::COMPONENTS, -INF);

        /** @var array<int, array{metrics: array<string, float>, tierPaid: bool, disabled: bool}> $bucket */
        $bucket = [];
        foreach ($pool as $candidate) {
            $student = $candidate->student;
            $bucket[$student->id] = [
                'metrics' => self::metrics($student),
                'tierPaid' => $student->isPaid,
                'disabled' => $student->isDisabled,
            ];
            foreach ($bucket[$student->id]['metrics'] as $component => $value) {
                if ($value < $mins[$component]) {
                    $mins[$component] = $value;
                }
                if ($value > $maxs[$component]) {
                    $maxs[$component] = $value;
                }
            }
        }

        $weights = $this->ratingWeights->weights();
        $disabilityBonus = $this->ratingWeights->bonuses()['disability'];

        $keys = [];
        foreach ($bucket as $studentId => $entry) {
            $weightedScore = 0.0;
            foreach ($entry['metrics'] as $component => $value) {
                $normalized = $maxs[$component] === $mins[$component]
                    ? 100.0
                    : (($value - $mins[$component]) / ($maxs[$component] - $mins[$component])) * 100.0;
                $weightedScore += $normalized * $weights[$component];
            }
            if ($entry['disabled']) {
                $weightedScore += $disabilityBonus;
            }
            $keys[$studentId] = [
                'tierPaid' => $entry['tierPaid'],
                'score' => (int) round($weightedScore * self::SCALE),
            ];
        }

        return $keys;
    }

    /**
     * Сырые значения компонентов студента (шкалы ADR-002/Q-4).
     *
     * @return array<string, float>
     */
    private static function metrics(Student $student): array
    {
        return [
            'entrance_exams' => (float) $student->entranceExamsSum,
            'gpa_sem12' => $student->gpaSem12,
            'gpa_basic' => $student->gpaBasic,
            'entrance_test' => (float) $student->entranceTestScore,
        ];
    }
}