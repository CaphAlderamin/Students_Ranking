<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * Веса компонентов рейтинга и связанные настройки (ADR-002).
 *
 * Значения задаются в `config/ranking.php` и валидируются здесь: известные ключи,
 * веса ≥ 0 (сумма ≈ 1.0), бонусы ≥ 0 по известным ключам, тир `paid_over_budget` — bool,
 * нормализация — из списка разрешённых.
 */
final readonly class RatingWeights
{
    /** Допуск при проверке суммы весов. */
    private const float SUM_EPSILON = 0.001;

    /** Ключи компонентов рейтинга (R-16 / ADR-002). */
    private const array WEIGHT_KEYS = [
        'entrance_exams',
        'gpa_sem12',
        'gpa_basic',
        'entrance_test',
    ];

    /** Ключи аддитивных бонусов (ADR-002). */
    private const array BONUS_KEYS = [
        'disability',
    ];

    /** Ключи тиров (ADR-002). */
    private const array TIER_KEYS = [
        'paid_over_budget',
    ];

    /** Разрешённые схемы нормализации (ADR-002). */
    private const array NORMALIZATIONS = [
        'min_max_0_100',
    ];

    /** @var array<string, float> */
    private readonly array $weights;

    /** @var array<string, float> */
    private readonly array $bonuses;

    /** @var array<string, bool> */
    private readonly array $tiers;

    private readonly string $normalization;

    /**
     * @param array<string, int|float> $weights веса компонентов (сумма ≈ 1.0)
     * @param array<string, int|float> $bonuses аддитивные бонусы (значения ≥ 0)
     * @param array<string, mixed>     $tiers   тиры (paid_over_budget — bool)
     */
    public function __construct(array $weights, array $bonuses, array $tiers, string $normalization)
    {
        self::assertKeys(self::WEIGHT_KEYS, $weights, 'RatingWeights: weights');
        $normalizedWeights = [];
        foreach ($weights as $key => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException("RatingWeights: вес '{$key}' не может быть отрицательным ({$value})");
            }
            $normalizedWeights[$key] = (float) $value;
        }
        $sum = array_sum($weights);
        if (abs($sum - 1.0) > self::SUM_EPSILON) {
            throw new \InvalidArgumentException(
                sprintf('RatingWeights: сумма весов должна быть ≈ 1.0 (±%s), фактически: %s', self::SUM_EPSILON, $sum),
            );
        }

        self::assertKeys(self::BONUS_KEYS, $bonuses, 'RatingWeights: bonuses');
        $normalizedBonuses = [];
        foreach ($bonuses as $key => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException("RatingWeights: бонус '{$key}' не может быть отрицательным ({$value})");
            }
            $normalizedBonuses[$key] = (float) $value;
        }

        self::assertKeys(self::TIER_KEYS, $tiers, 'RatingWeights: tiers');
        $normalizedTiers = [];
        foreach ($tiers as $key => $value) {
            if (!is_bool($value)) {
                throw new \InvalidArgumentException("RatingWeights: тир '{$key}' должен быть boolean, получено: " . gettype($value));
            }
            $normalizedTiers[$key] = $value;
        }

        if (!in_array($normalization, self::NORMALIZATIONS, true)) {
            throw new \InvalidArgumentException(
                "RatingWeights: неизвестная нормализация '{$normalization}'; разрешены: " . implode(', ', self::NORMALIZATIONS),
            );
        }

        $this->weights = $normalizedWeights;
        $this->bonuses = $normalizedBonuses;
        $this->tiers = $normalizedTiers;
        $this->normalization = $normalization;
    }

    /** @return array<string, float> веса компонентов */
    public function weights(): array
    {
        return $this->weights;
    }

    /** @return array<string, float> аддитивные бонусы */
    public function bonuses(): array
    {
        return $this->bonuses;
    }

    /** @return array<string, bool> тиры */
    public function tiers(): array
    {
        return $this->tiers;
    }

    public function normalization(): string
    {
        return $this->normalization;
    }

    /**
     * Проверяет, что массив содержит ровно ожидаемый набор ключей.
     *
     * @param list<string>        $expected ожидаемые ключи
     * @param array<string, mixed> $actual  фактический массив
     */
    private static function assertKeys(array $expected, array $actual, string $context): void
    {
        $missing = array_diff($expected, array_keys($actual));
        $extra = array_diff(array_keys($actual), $expected);
        if ($missing !== [] || $extra !== []) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s: неверный набор ключей; лишние: [%s], отсутствующие: [%s]',
                    $context,
                    implode(', ', $extra),
                    implode(', ', $missing),
                ),
            );
        }
    }
}