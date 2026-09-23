<?php

declare(strict_types=1);

namespace App\Ui\Cli;

use App\Application\Ranking\CriteriaBasedRanking;
use App\Application\Ranking\CriteriaRating;
use App\Application\Ranking\DateBasedRanking;
use App\Application\Ranking\RankingStrategyInterface;

/**
 * Чистая фабрика стратегий ранжирования (B3-05, план §2.6).
 *
 * Маппинг CLI-опции `--algorithm=date|criteria` на реализацию RankingStrategyInterface
 * вынесен в отдельную фабрику, чтобы маппинг покрывался юнит-тестами без БД и
 * реальных конфигов. Значения опции совпадают с
 * {@see \App\Domain\Enum\RankingAlgorithm::value}.
 *
 * R-17: допустимы только две стратегии; иное значение — \InvalidArgumentException
 * (перехватывается командой → stderr, exit ≠ 0).
 */
final class RankingStrategyFactory
{
    /** Значение опции, соответствующее алгоритму 1 (по дате подачи). */
    public const string DATE_ALGORITHM = 'date';

    /** Значение опции, соответствующее алгоритму 2 (по критериям). */
    public const string CRITERIA_ALGORITHM = 'criteria';

    /**
     * Создаёт стратегию по имени алгоритма CLI-опции.
     *
     * @param string         $algorithm значение --algorithm (date|criteria)
     * @param CriteriaRating $rating    критериальный ключ (ADR-002): общий для обеих стратегий
     *
     * @throws \InvalidArgumentException при неизвестном алгоритме (R-17)
     */
    public static function createStrategy(string $algorithm, CriteriaRating $rating): RankingStrategyInterface
    {
        return match ($algorithm) {
            self::DATE_ALGORITHM => new DateBasedRanking($rating),
            self::CRITERIA_ALGORITHM => new CriteriaBasedRanking($rating),
            default => throw new \InvalidArgumentException(
                'Неизвестный алгоритм "' . $algorithm . '"; разрешены: ' . self::DATE_ALGORITHM . '|' . self::CRITERIA_ALGORITHM,
            ),
        };
    }
}